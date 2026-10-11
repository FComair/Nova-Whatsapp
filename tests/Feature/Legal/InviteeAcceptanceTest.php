<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Legal\Services\InviteeAcceptanceRepair;
use App\Modules\Legal\Services\LegalDocuments;
use App\Modules\Team\Support\Capabilities;
use App\Modules\Tenancy\Models\OrgMember;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Acceptation des documents juridiques par une personne invitée (docs/28) : le compte et ses acceptations naissent dans la même opération,
 * le tableau de bord ne réclame plus rien, l'échec n'enregistre pas de compte, et la réparation ne fabrique jamais une acceptation.
 */
function inviteeMailToken(): string
{
    $mails = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->map(function ($sent): ?string {
        preg_match('#/invitation/([A-Za-z0-9]{48})#', (string) $sent->getOriginalMessage()->getHtmlBody(), $match);

        return $match[1] ?? null;
    })->filter()->values();

    return (string) $mails->last();
}

function inviteeLegalCleanup(): void
{
    DB::connection('pgsql_owner')->unprepared("ALTER TABLE legal_document_versions DISABLE TRIGGER legal_versions_freeze; DELETE FROM legal_document_versions WHERE version <> '2026-10-01'; ALTER TABLE legal_document_versions ENABLE TRIGGER legal_versions_freeze;");
}

function inviteeUnblockConsents(): void
{
    DB::connection('pgsql_owner')->unprepared('DROP TRIGGER IF EXISTS test_block_consents ON user_consents; DROP FUNCTION IF EXISTS test_block_consents()');
}

beforeEach(function (): void {
    inviteeLegalCleanup();
    inviteeUnblockConsents();
    $this->blockConsents = fn () => DB::connection('pgsql_owner')->unprepared(<<<'SQL'
        CREATE FUNCTION test_block_consents() RETURNS trigger LANGUAGE plpgsql AS $f$ BEGIN RAISE EXCEPTION 'écriture refusée (test)'; END $f$;
        CREATE TRIGGER test_block_consents BEFORE INSERT ON user_consents FOR EACH ROW EXECUTE FUNCTION test_block_consents()
    SQL);
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['signup_plan' => 'business', 'name' => 'Salon Aurore', 'locale' => 'fr']);

    $this->db = fn () => DB::connection('pgsql_owner');
    $this->consents = fn (string $userId) => ($this->db)()->table('user_consents')->where('user_id', $userId)->orderBy('type')->get();
    $this->invite = function (string $email = 'elodie@example.com'): string {
        $this->signedIn($this->owner, $this->org)->post('/reglages/equipe', ['name' => 'Élodie Martin', 'email' => $email, 'role' => 'receptionist', 'capabilities' => Capabilities::defaults('receptionist')])->assertSessionHasNoErrors();
        auth()->forgetGuards();

        return inviteeMailToken();
    };
    $this->payload = fn (array $override = []): array => array_merge([
        'password' => 'Sol1de-Phrase-Pour-Test!', 'password_confirmation' => 'Sol1de-Phrase-Pour-Test!', 'accept_terms' => true, 'accept_privacy' => true,
        'legal_versions' => ['terms' => '2026-10-01', 'privacy' => '2026-10-01'],
    ], $override);
    $this->accept = fn (string $token, array $override = []) => $this->withHeader('User-Agent', 'Mozilla/5.0 (Test)')->post("/invitation/{$token}", ($this->payload)($override));
    $this->userByEmail = fn (string $email): ?User => $this->asOwner(fn () => User::query()->where('email', $email)->first());
    // Compte tel que l'ancien code le créait : membre invité et entrée « invitation.accepted » au journal, mais aucune acceptation.
    $this->legacy = fn (string $email, bool $withEvidence): User => $this->asOwner(function () use ($email, $withEvidence): User {
        $user = User::factory()->create(['email' => $email, 'locale' => 'fr']);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'receptionist', 'status' => 'active']);

        if ($withEvidence) {
            DB::connection('pgsql_owner')->table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'org_id' => $this->org->id, 'actor_type' => 'user', 'actor_id' => $user->id, 'action' => 'invitation.accepted', 'entity_type' => 'invitations',
                'entity_id' => (string) Str::uuid(), 'ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 (Legacy)', 'created_at' => now(),
            ]);
        }

        return $user;
    });
    $this->publish = function (string $document, bool $significant, string $version): void {
        ($this->db)()->unprepared('ALTER TABLE legal_document_versions DISABLE TRIGGER legal_versions_freeze');
        ($this->db)()->table('legal_document_versions')->insert([
            'id' => (string) Str::uuid(), 'document' => $document, 'version' => $version, 'effective_on' => now()->toDateString(), 'status' => 'published', 'requires_acceptance' => $significant,
            'title' => json_encode(['fr' => 'Titre', 'en' => 'Title', 'nl' => 'Titel']), 'body' => json_encode(['fr' => '# Titre', 'en' => '# Title', 'nl' => '# Titel']), 'changes' => json_encode([]),
            'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        ($this->db)()->unprepared('ALTER TABLE legal_document_versions ENABLE TRIGGER legal_versions_freeze');
    };
});

afterEach(function (): void {
    inviteeUnblockConsents();
    inviteeLegalCleanup();
});

describe('création du compte par une personne invitée', function (): void {
    it('enregistre les conditions et la confidentialité, liées au compte créé, avec la version, la date, l\'IP et l\'agent', function (): void {
        $token = ($this->invite)();

        ($this->accept)($token)->assertSessionHasNoErrors()->assertRedirect('/tableau-de-bord');

        $user = ($this->userByEmail)('elodie@example.com');
        $rows = ($this->consents)($user->id);

        expect($rows->pluck('type')->all())->toBe(['privacy', 'terms'])
            ->and($rows->pluck('document_version')->unique()->all())->toBe(['2026-10-01'])
            ->and($rows->pluck('source')->unique()->all())->toBe(['invitation'])
            ->and($rows->pluck('user_agent')->unique()->all())->toBe(['Mozilla/5.0 (Test)'])
            ->and($rows->pluck('ip')->filter()->count())->toBe(2)
            ->and($rows->pluck('locale')->unique()->all())->toBe(['fr'])
            ->and($rows->every(fn ($row): bool => $row->granted_at !== null && $row->user_id === $user->id))->toBeTrue();
    });

    it('ne rattache ces acceptations à aucun autre compte et n\'enregistre pas le DPA, qui lie l\'entreprise', function (): void {
        $before = ($this->consents)($this->owner->id)->count();
        $token = ($this->invite)();

        ($this->accept)($token);

        $user = ($this->userByEmail)('elodie@example.com');
        expect(($this->consents)($this->owner->id)->count())->toBe($before)
            ->and(($this->db)()->table('user_consents')->where('user_id', $user->id)->where('type', 'dpa')->count())->toBe(0)
            ->and(($this->db)()->table('user_consents')->where('source', 'invitation')->pluck('user_id')->unique()->all())->toBe([$user->id])
            ->and(($this->db)()->table('audit_logs')->where('action', 'legal.accepted')->where('actor_id', $user->id)->count())->toBe(2);
    });

    it('n\'affiche plus l\'avis « aucune acceptation » sur le tableau de bord', function (): void {
        $token = ($this->invite)();
        ($this->accept)($token);
        $user = ($this->userByEmail)('elodie@example.com');
        auth()->forgetGuards();

        $this->signedIn($user, $this->org)->get('/tableau-de-bord')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('legal.pending', []));
    });

    it('garde l\'acceptation après déconnexion et nouvelle connexion', function (): void {
        $token = ($this->invite)();
        ($this->accept)($token);
        $user = ($this->userByEmail)('elodie@example.com');

        $this->post('/deconnexion');
        auth()->forgetGuards();

        expect(($this->consents)($user->id))->toHaveCount(2);
        $this->signedIn($user, $this->org)->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('legal.pending', []));
    });

    it('fonctionne aussi pour un praticien invité', function (): void {
        $this->signedIn($this->owner, $this->org)->post('/configuration/praticiens/invitation', ['name' => 'Léa Bernard', 'email' => 'lea@example.com'])->assertSessionHasNoErrors();
        auth()->forgetGuards();

        ($this->accept)(inviteeMailToken())->assertSessionHasNoErrors();

        expect(($this->consents)(($this->userByEmail)('lea@example.com')->id)->pluck('type')->all())->toBe(['privacy', 'terms']);
    });
});

describe('échec et versions', function (): void {
    it('ne crée pas le compte quand l\'acceptation ne peut pas être enregistrée, et le dit clairement', function (): void {
        Log::spy();
        $token = ($this->invite)();
        ($this->blockConsents)();

        $response = ($this->accept)($token);

        $response->assertSessionHasErrors(['legal' => 'Nous n’avons pas pu enregistrer votre acceptation. Veuillez réessayer.']);
        expect(($this->userByEmail)('elodie@example.com'))->toBeNull()
            ->and(($this->db)()->table('invitations')->whereNotNull('used_at')->count())->toBe(0)
            ->and(($this->db)()->table('org_members')->where('name', 'Élodie Martin')->count())->toBe(0);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'acceptation') && $context['missing'] === ['terms', 'privacy'] && ! str_contains(json_encode($context), 'elodie'));
    });

    it('permet de réessayer avec le même lien après un échec', function (): void {
        $token = ($this->invite)();
        ($this->blockConsents)();
        ($this->accept)($token)->assertSessionHasErrors('legal');
        inviteeUnblockConsents();

        ($this->accept)($token)->assertSessionHasNoErrors();

        expect(($this->consents)(($this->userByEmail)('elodie@example.com')->id))->toHaveCount(2);
    });

    it('refuse si les documents ont changé entre l\'affichage et l\'envoi', function (): void {
        $token = ($this->invite)();
        ($this->publish)('terms', true, '2026-10-02');

        ($this->accept)($token)->assertSessionHasErrors('legal');

        expect(($this->userByEmail)('elodie@example.com'))->toBeNull();
    });

    it('exige les deux cases et les versions affichées', function (array $override, string $field): void {
        $token = ($this->invite)();

        ($this->accept)($token, $override)->assertSessionHasErrors($field);

        expect(($this->userByEmail)('elodie@example.com'))->toBeNull();
    })->with([
        'conditions refusées' => [['accept_terms' => false], 'accept_terms'],
        'confidentialité refusée' => [['accept_privacy' => false], 'accept_privacy'],
        'versions absentes' => [['legal_versions' => null], 'legal_versions'],
    ]);

    it('affiche les documents et leur version sur la page d\'invitation', function (): void {
        $token = ($this->invite)();

        $this->get("/invitation/{$token}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Invitation/Accept')
            ->where('legal.documents', fn ($documents) => collect($documents)->pluck('code')->all() === ['terms', 'privacy'] && collect($documents)->pluck('version')->unique()->all() === ['2026-10-01']));
    });
});

describe('état affiché au tableau de bord', function (): void {
    it('nomme exactement le document manquant quand une personne n\'en a accepté qu\'un', function (): void {
        $user = $this->asOwner(function (): User {
            $user = User::factory()->create(['email' => 'partiel@example.com']);
            OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'receptionist', 'status' => 'active']);

            return $user;
        });
        $this->asOwner(fn () => app(LegalDocuments::class)->accept($user, ['terms'], 'settings', false, null, null, 'fr', $this->org->id));

        $this->signedIn($user, $this->org)->get('/tableau-de-bord')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('legal.pending', fn ($pending) => collect($pending)->pluck('code')->all() === ['privacy'] && collect($pending)->first()['title'] !== ''));
    });

    it('réclame la nouvelle version après la publication d\'une version significative', function (): void {
        $token = ($this->invite)();
        ($this->accept)($token);
        $user = ($this->userByEmail)('elodie@example.com');
        ($this->publish)('privacy', true, '2026-10-02');
        auth()->forgetGuards();

        $state = $this->asOwner(fn () => collect(app(LegalDocuments::class)->statuses($user, false, 'fr'))->pluck('state', 'code')->all());
        expect($state)->toBe(['terms' => 'accepted', 'privacy' => 'reacceptance', 'dpa' => 'not_applicable']);

        $this->signedIn($user, $this->org)->get('/clients')->assertRedirect('/reglages/juridique');
    });
});

describe('réparation des comptes invités avant le correctif', function (): void {
    it('rapporte sans rien écrire par défaut', function (): void {
        ($this->legacy)('ancien@example.com', true);

        expect(Artisan::call('legal:repair-invitee-acceptances'))->toBe(0)->and(Artisan::output())->toContain('2 acceptation(s) prouvée(s)')->toContain('Rapport seulement');
        expect(($this->db)()->table('user_consents')->where('source', 'invitation_recovered')->count())->toBe(0);
    });

    it('retrouve l\'acceptation prouvée par le journal : date, IP, agent et version en vigueur à ce moment', function (): void {
        $user = ($this->legacy)('ancien@example.com', true);

        Artisan::call('legal:repair-invitee-acceptances', ['--apply' => true]);

        $rows = ($this->consents)($user->id);
        expect($rows->pluck('type')->all())->toBe(['privacy', 'terms'])
            ->and($rows->pluck('source')->unique()->all())->toBe(['invitation_recovered'])
            ->and($rows->pluck('document_version')->unique()->all())->toBe(['2026-10-01'])
            ->and($rows->pluck('ip')->unique()->all())->toBe(['203.0.113.7'])
            ->and($rows->pluck('user_agent')->unique()->all())->toBe(['Mozilla/5.0 (Legacy)'])
            ->and(($this->db)()->table('audit_logs')->where('action', 'legal.acceptance_recovered')->count())->toBe(2);

        $this->signedIn($user, $this->org)->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('legal.pending', []));
    });

    it('est idempotente', function (): void {
        ($this->legacy)('ancien@example.com', true);
        Artisan::call('legal:repair-invitee-acceptances', ['--apply' => true]);

        Artisan::call('legal:repair-invitee-acceptances', ['--apply' => true]);

        expect(($this->db)()->table('user_consents')->where('source', 'invitation_recovered')->count())->toBe(2);
    });

    it('n\'invente rien sans preuve : la personne reste à accepter et voit l\'avis', function (): void {
        $user = ($this->legacy)('sansTrace@example.com', false);

        $plan = app(InviteeAcceptanceRepair::class)->plan();
        Artisan::call('legal:repair-invitee-acceptances', ['--apply' => true]);

        expect($plan['recoverable'])->toBe([])->and($plan['unresolved'])->toHaveCount(1)->and($plan['unresolved'][0]['reason'])->toBe('no_evidence')
            ->and(($this->consents)($user->id))->toHaveCount(0);
        $this->signedIn($user, $this->org)->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page
            ->where('legal.pending', fn ($pending) => collect($pending)->pluck('code')->all() === ['terms', 'privacy']));
    });

    it('n\'invente rien quand aucune version n\'était publiée à la date de l\'acceptation', function (): void {
        $user = $this->asOwner(function (): User {
            $user = User::factory()->create(['email' => 'tropancien@example.com']);
            OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'receptionist', 'status' => 'active']);
            DB::connection('pgsql_owner')->table('audit_logs')->insert(['id' => (string) Str::uuid(), 'org_id' => $this->org->id, 'actor_type' => 'user', 'actor_id' => $user->id, 'action' => 'invitation.accepted', 'created_at' => '2020-01-01 10:00:00']);

            return $user;
        });

        $plan = app(InviteeAcceptanceRepair::class)->plan();

        expect($plan['recoverable'])->toBe([])->and(collect($plan['unresolved'])->pluck('reason')->unique()->all())->toBe(['no_version_at_that_date']);
        expect(($this->consents)($user->id))->toHaveCount(0);
    });

    it('ne touche ni un propriétaire ni une personne qui a déjà accepté', function (): void {
        $done = ($this->legacy)('deja@example.com', true);
        $this->asOwner(fn () => app(LegalDocuments::class)->accept($done, ['terms', 'privacy'], 'settings', false, null, null, 'fr', $this->org->id));
        $before = ($this->db)()->table('user_consents')->count();

        Artisan::call('legal:repair-invitee-acceptances', ['--apply' => true]);

        expect(($this->db)()->table('user_consents')->count())->toBe($before)->and(($this->consents)($done->id)->pluck('source')->unique()->all())->toBe(['settings']);
    });
});
