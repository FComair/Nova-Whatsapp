<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\LegalVersionResource\Pages\ListLegalVersions;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformLegalVersion;
use App\Modules\Admin\Services\LegalVersionManager;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Legal\Services\LegalDocuments;
use App\Modules\Tenancy\Models\OrgMember;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Documents juridiques (docs/28) : acceptation à l'inscription, pages publiques stables, versions figées, historique d'acceptation,
 * Réglages › Juridique, nouvelle acceptation et publication par le Super Admin. Première version : « 2026-10-01 » (en vigueur depuis le 01/01/2026, fichiers resources/legal).
 */
function legalCleanup(): void
{
    // Les versions publiées sont figées (même pour le propriétaire de la base) : on coupe le déclencheur le temps de nettoyer les versions ajoutées par un test.
    DB::connection('pgsql_owner')->unprepared("ALTER TABLE legal_document_versions DISABLE TRIGGER legal_versions_freeze; DELETE FROM legal_document_versions WHERE version <> '2026-10-01'; ALTER TABLE legal_document_versions ENABLE TRIGGER legal_versions_freeze;");
    config(['nova.legal.dpa_required_at_signup' => true]);
}

beforeEach(function (): void {
    legalCleanup();
    Notification::fake();

    $this->owner = fn (callable $callback): mixed => $this->asOwner($callback);
    $this->versionOf = fn (string $code, string $version = '2026-10-01') => $this->asOwner(fn () => DB::table('legal_document_versions')->where('document', $code)->where('version', $version)->first());

    // Publie une nouvelle version (directement en base, comme le ferait le Super Admin une fois validée).
    $this->publish = fn (string $code, string $version, ?string $effective = null, bool $requires = true, array $title = ['fr' => 'Titre', 'en' => 'Title'], array $body = ['fr' => "## 1. Un\nTexte français.", 'en' => "## 1. One\nEnglish text."], array $changes = []) => $this->asOwner(fn () => DB::table('legal_document_versions')->insert([
        'id' => (string) Str::uuid7(), 'document' => $code, 'version' => $version, 'effective_on' => $effective ?? now()->subDay()->toDateString(), 'status' => 'published', 'requires_acceptance' => $requires,
        'title' => json_encode($title), 'body' => json_encode($body), 'changes' => json_encode((object) $changes), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->consents = fn (User $user, string $code = '*') => $this->asOwner(fn () => DB::table('user_consents')->where('user_id', $user->id)->when($code !== '*', fn ($q) => $q->where('type', $code))->whereIn('type', ['terms', 'privacy', 'dpa'])->orderBy('type')->get()->all());

    // Un compte déjà connu, avec ses acceptations de la version 2026-10-01 (comme après l'inscription).
    $this->account = function (string $role = 'owner', bool $withConsents = true, string $email = 'proprio@example.com'): array {
        ['user' => $user, 'org' => $org] = $this->activatedAccount(email: $email, role: $role);

        if ($withConsents) {
            foreach (['terms', 'privacy', 'dpa'] as $type) {
                $this->asOwner(fn () => DB::table('user_consents')->insert(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'type' => $type, 'document_version' => '2026-10-01', 'granted_at' => now()->subDays(3), 'source' => 'signup', 'created_at' => now(), 'updated_at' => now()]));
            }
        }

        $this->current = [$user, $org];

        return [$user, $org];
    };
    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->current[0], $this->current[1]);
    $this->page = fn (string $url, ?User $user = null): array => Assert::fromTestResponse(($this->as)($user)->get($url)->assertOk())->toArray()['props'];
});

afterEach(fn () => legalCleanup());

describe('inscription', function (): void {
    it('affiche les trois documents avec leur titre, leur version et leur adresse stable', function (): void {
        $props = Assert::fromTestResponse($this->get('/inscription')->assertOk())->toArray()['props'];

        expect($props['legal']['dpa_required'])->toBeTrue()
            ->and(collect($props['legal']['documents'])->pluck('href', 'code')->all())->toBe(['terms' => '/legal/terms-of-use', 'privacy' => '/legal/privacy-policy', 'dpa' => '/legal/dpa'])
            ->and(collect($props['legal']['documents'])->pluck('version')->unique()->all())->toBe(['2026-10-01'])
            ->and(collect($props['legal']['documents'])->pluck('title', 'code')->all())->toBe(['terms' => 'Conditions générales d’utilisation', 'privacy' => 'Politique de confidentialité', 'dpa' => 'Accord de sous-traitance des données (DPA)']);
    });

    it('exige chaque acceptation, séparément, et ne crée rien tant qu\'il en manque une', function (string $missing): void {
        $this->post('/inscription', $this->validRegistration([$missing => false]))->assertSessionHasErrors($missing)->assertSessionHasInput('email', 'nouveau@example.com');

        expect($this->asOwner(fn () => DB::table('users')->count()))->toBe(0)->and($this->asOwner(fn () => DB::table('organizations')->count()))->toBe(0);
        Notification::assertNothingSent();
    })->with(['accept_terms', 'accept_privacy', 'accept_dpa']);

    it('n\'accepte pas une case absente de la demande (jamais précochée côté serveur)', function (): void {
        $data = $this->validRegistration();
        unset($data['accept_terms'], $data['accept_privacy'], $data['accept_dpa']);

        $this->post('/inscription', $data)->assertSessionHasErrors(['accept_terms', 'accept_privacy', 'accept_dpa']);
    });

    it('garde les coordonnées saisies quand une acceptation manque (hors mot de passe)', function (): void {
        $response = $this->from('/inscription')->post('/inscription', $this->validRegistration(['accept_privacy' => false]));

        $response->assertRedirect('/inscription')->assertSessionHasInput('email', 'nouveau@example.com')->assertSessionHasInput('phone', '+32 470 12 34 56')->assertSessionHasInput('sector', 'hairdressing');
    });

    it('enregistre pour chaque document la version, l\'horodatage, l\'IP, l\'agent utilisateur, la langue et le contexte, plus une entrée d\'audit', function (): void {
        $this->withHeader('User-Agent', 'NovaTest/1.0')->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeader('Accept-Language', 'en')->post('/inscription', $this->validRegistration())->assertRedirect('/verification-email');

        $user = $this->asOwner(fn () => User::query()->where('email', 'nouveau@example.com')->firstOrFail());
        $rows = ($this->consents)($user);

        expect(collect($rows)->pluck('type')->all())->toBe(['dpa', 'privacy', 'terms'])
            ->and(collect($rows)->pluck('document_version')->unique()->all())->toBe(['2026-10-01'])
            ->and(collect($rows)->pluck('source')->unique()->all())->toBe(['signup'])
            ->and(collect($rows)->pluck('ip')->unique()->all())->toBe(['203.0.113.7'])
            ->and(collect($rows)->pluck('user_agent')->unique()->all())->toBe(['NovaTest/1.0'])
            ->and(collect($rows)->pluck('locale')->unique()->all())->toBe(['en'])
            ->and(collect($rows)->every(fn ($row) => $row->granted_at !== null))->toBeTrue();

        $audit = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'legal.accepted')->where('actor_id', $user->id)->orderBy('id')->get());
        expect($audit)->toHaveCount(3)->and(collect($audit)->every(fn ($row) => $row->org_id !== null && $row->ip === '203.0.113.7'))->toBeTrue()
            ->and(collect($audit)->map(fn ($row) => json_decode($row->diff, true)['context'])->unique()->all())->toBe(['signup'])
            ->and(collect($audit)->map(fn ($row) => json_decode($row->diff, true)['document'])->sort()->values()->all())->toBe(['dpa', 'privacy', 'terms']);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    });

    it('le DPA n\'est exigé que si la plateforme l\'exige : sinon la case est facultative et n\'enregistre rien tant qu\'elle n\'est pas cochée', function (): void {
        config(['nova.legal.dpa_required_at_signup' => false]);

        $this->post('/inscription', $this->validRegistration(['accept_dpa' => false]))->assertRedirect('/verification-email');
        $user = $this->asOwner(fn () => User::query()->where('email', 'nouveau@example.com')->firstOrFail());

        expect(collect(($this->consents)($user))->pluck('type')->sort()->values()->all())->toBe(['privacy', 'terms']);

        $this->post('/inscription', $this->validRegistration(['email' => 'autre@example.com', 'accept_dpa' => true]))->assertRedirect('/verification-email');
        $other = $this->asOwner(fn () => User::query()->where('email', 'autre@example.com')->firstOrFail());

        expect(collect(($this->consents)($other))->pluck('type')->sort()->values()->all())->toBe(['dpa', 'privacy', 'terms']);
    });

    it('refuse l\'envoi si un document a changé depuis son affichage, et enregistre la nouvelle version après une nouvelle acceptation', function (): void {
        ($this->publish)('terms', '2026-12-01');

        $this->post('/inscription', $this->validRegistration())->assertSessionHasErrors(['legal' => 'Les documents juridiques ont été mis à jour pendant votre inscription. Relisez-les, puis cochez de nouveau les cases.']);
        expect($this->asOwner(fn () => DB::table('users')->count()))->toBe(0);

        $this->post('/inscription', $this->validRegistration(['legal_versions' => ['terms' => '2026-12-01', 'privacy' => '2026-10-01', 'dpa' => '2026-10-01']]))->assertRedirect('/verification-email');
        $user = $this->asOwner(fn () => User::query()->where('email', 'nouveau@example.com')->firstOrFail());

        expect(collect(($this->consents)($user))->pluck('document_version', 'type')->all())->toBe(['dpa' => '2026-10-01', 'privacy' => '2026-10-01', 'terms' => '2026-12-01']);
    });

    it('exige les versions vues avec la demande', function (): void {
        $data = $this->validRegistration();
        unset($data['legal_versions']);

        $this->post('/inscription', $data)->assertSessionHasErrors('legal_versions');
    });

    it('refuse l\'inscription quand une version future n\'est pas encore en vigueur : on accepte la version en vigueur', function (): void {
        ($this->publish)('privacy', '2027-01-01', now()->addDays(30)->toDateString());

        $props = Assert::fromTestResponse($this->get('/inscription')->assertOk())->toArray()['props'];

        expect(collect($props['legal']['documents'])->firstWhere('code', 'privacy')['version'])->toBe('2026-10-01');
        $this->post('/inscription', $this->validRegistration())->assertRedirect('/verification-email');
    });
});

describe('pages publiques', function (): void {
    it('ouvrent sans connexion, aux adresses stables, avec titre, version, date d\'effet et sommaire', function (string $slug, string $title): void {
        $this->get("/legal/{$slug}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Legal/Show')->where('document.slug', $slug)->where('document.title', $title)->where('document.version', '2026-10-01')->where('document.effective_on', '2026-01-01')
            ->where('document.is_current', true)->where('document.fallback', false)->has('document.toc', fn (Assert $toc) => $toc->etc())->has('documents', 3));
    })->with([['terms-of-use', 'Conditions générales d’utilisation'], ['privacy-policy', 'Politique de confidentialité'], ['dpa', 'Accord de sous-traitance des données (DPA)']]);

    it('donnent des ancres de section qui correspondent au sommaire, et remplacent la raison sociale', function (): void {
        $props = Assert::fromTestResponse($this->get('/legal/terms-of-use')->assertOk())->toArray()['props']['document'];

        expect($props['toc'])->not->toBeEmpty()->and($props['toc'][0])->toBe(['id' => 'section-1', 'title' => '1. Objet et acceptation']);

        foreach ($props['toc'] as $item) {
            expect($props['html'])->toContain('id="'.$item['id'].'"');
        }

        expect($props['html'])->not->toContain('{{company}}')->and($props['html'])->toContain((string) config('nova.billing.seller.name'));
    });

    it('suivent la langue de l\'interface, retombent sur le français avec un avis, et fonctionnent en anglais', function (): void {
        $english = Assert::fromTestResponse($this->withHeader('Accept-Language', 'en')->get('/legal/privacy-policy')->assertOk())->toArray()['props']['document'];
        expect($english)->toMatchArray(['title' => 'Privacy Policy', 'language' => 'en', 'fallback' => false]);

        $dutch = Assert::fromTestResponse($this->withHeader('Accept-Language', 'nl')->get('/legal/privacy-policy')->assertOk())->toArray()['props']['document'];
        expect($dutch)->toMatchArray(['title' => 'Politique de confidentialité', 'language' => 'fr', 'fallback' => true]);
    });

    it('n\'exposent jamais de HTML brut venu d\'un texte', function (): void {
        ($this->publish)('terms', '2026-12-01', null, false, ['fr' => 'Titre'], ['fr' => "## 1. Un\nTexte <script>alert(1)</script> [lien](javascript:alert(1))"]);

        $html = Assert::fromTestResponse($this->get('/legal/terms-of-use')->assertOk())->toArray()['props']['document']['html'];

        expect($html)->not->toContain('<script')->and($html)->not->toContain('javascript:');
    });

    it('répondent 404 pour un document inconnu, une version inconnue, un brouillon ou une version pas encore en vigueur', function (): void {
        $this->asOwner(fn () => DB::table('legal_document_versions')->insert([
            'id' => (string) Str::uuid7(), 'document' => 'terms', 'version' => 'brouillon', 'effective_on' => now()->addDay()->toDateString(), 'status' => 'draft', 'requires_acceptance' => false,
            'title' => json_encode(['fr' => 'X']), 'body' => json_encode(['fr' => 'X']), 'changes' => json_encode((object) []), 'created_at' => now(), 'updated_at' => now(),
        ]));
        ($this->publish)('terms', '2027-06-01', now()->addDays(60)->toDateString());

        foreach (['/legal/cookies', '/legal/terms-of-use/versions/inconnue', '/legal/terms-of-use/versions/brouillon', '/legal/terms-of-use/versions/2027-06-01', '/legal/cookies/download'] as $url) {
            $this->get($url)->assertNotFound();
        }
    });

    it('gardent chaque version à son adresse, la version en vigueur à l\'adresse stable et un historique', function (): void {
        ($this->publish)('terms', '2026-12-01', now()->subDay()->toDateString(), true, ['fr' => 'Nouveau titre'], ['fr' => "## 1. Nouveau\nTexte neuf."], ['fr' => 'Durée de conservation précisée.']);

        $current = Assert::fromTestResponse($this->get('/legal/terms-of-use')->assertOk())->toArray()['props'];
        $old = Assert::fromTestResponse($this->get('/legal/terms-of-use/versions/2026-10-01')->assertOk())->toArray()['props'];

        expect($current['document'])->toMatchArray(['version' => '2026-12-01', 'title' => 'Nouveau titre', 'is_current' => true, 'changes' => 'Durée de conservation précisée.'])
            ->and($old['document'])->toMatchArray(['version' => '2026-10-01', 'is_current' => false, 'current_url' => '/legal/terms-of-use'])
            ->and(collect($current['versions'])->pluck('version')->all())->toBe(['2026-12-01', '2026-10-01'])
            ->and(collect($current['versions'])->firstWhere('version', '2026-12-01')['current'])->toBeTrue();
    });

    it('permettent de télécharger la version en vigueur en Markdown', function (): void {
        $response = $this->get('/legal/dpa/download')->assertOk();

        expect($response->headers->get('Content-Disposition'))->toContain('dpa-2026-10-01.md')->and($response->headers->get('Content-Type'))->toContain('text/markdown')
            ->and($response->getContent())->toStartWith('# Accord de sous-traitance des données (DPA)');
    });

    it('restent lisibles pour un utilisateur connecté', function (): void {
        ($this->account)();

        ($this->as)()->get('/legal/terms-of-use')->assertOk();
    });
});

describe('versions et historique figés', function (): void {
    it('une version publiée ne peut plus être modifiée ni supprimée, même par la base propriétaire', function (): void {
        expect(fn () => $this->asOwner(fn () => DB::table('legal_document_versions')->where('document', 'terms')->update(['body' => json_encode(['fr' => 'Autre texte'])])))->toThrow(QueryException::class)
            ->and(fn () => $this->asOwner(fn () => DB::table('legal_document_versions')->where('document', 'terms')->delete()))->toThrow(QueryException::class)
            ->and(fn () => DB::connection('pgsql_platform')->table('legal_document_versions')->where('document', 'terms')->update(['requires_acceptance' => false]))->toThrow(QueryException::class);
    });

    it('un client ne peut rien écrire dans les documents', function (): void {
        expect(fn () => DB::table('legal_document_versions')->where('document', 'terms')->update(['requires_acceptance' => false]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('legal_document_versions')->insert(['id' => (string) Str::uuid7(), 'document' => 'terms', 'version' => 'x', 'effective_on' => now()->toDateString(), 'status' => 'draft', 'title' => '{}', 'body' => '{}', 'changes' => '{}', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
    });

    it('une acceptation enregistrée ne change plus, et la même version ne s\'enregistre pas deux fois', function (): void {
        [$user] = ($this->account)();

        expect(fn () => $this->asOwner(fn () => DB::table('user_consents')->where('user_id', $user->id)->where('type', 'terms')->update(['document_version' => '1999'])))->toThrow(QueryException::class)
            ->and(fn () => $this->asOwner(fn () => DB::table('user_consents')->insert(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'type' => 'terms', 'document_version' => '2026-10-01', 'granted_at' => now(), 'source' => 'signup', 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class);
    });

    it('un nouveau texte est une nouvelle version : l\'acceptation passée garde sa version', function (): void {
        [$user] = ($this->account)();
        ($this->publish)('terms', '2026-12-01');

        expect(collect(($this->consents)($user, 'terms'))->pluck('document_version')->all())->toBe(['2026-10-01']);
    });
});

describe('Réglages › Juridique', function (): void {
    it('exige d\'être connecté', function (): void {
        $this->get('/reglages/juridique')->assertRedirect('/connexion');
        $this->post('/reglages/juridique/accepter', ['documents' => ['terms-of-use']])->assertRedirect('/connexion');
    });

    it('montre pour chaque document la version en vigueur, la version et la date acceptées', function (): void {
        ($this->account)();

        $documents = collect(($this->page)('/reglages/juridique')['documents'])->keyBy('slug');

        expect($documents->keys()->all())->toBe(['terms-of-use', 'privacy-policy', 'dpa'])
            ->and($documents['terms-of-use'])->toMatchArray(['state' => 'accepted', 'version' => '2026-10-01', 'effective_on' => '2026-01-01', 'blocking' => false])
            ->and($documents['terms-of-use']['accepted'])->toMatchArray(['version' => '2026-10-01', 'context' => 'signup'])->and($documents['terms-of-use']['accepted']['at'])->not->toBeNull()
            ->and($documents['terms-of-use']['history'])->toHaveCount(1);
    });

    it('n\'enregistre rien et n\'oblige à rien du seul fait de la visiter', function (): void {
        ($this->account)(withConsents: false);

        ($this->page)('/reglages/juridique');
        ($this->page)('/reglages/juridique');

        expect($this->asOwner(fn () => DB::table('user_consents')->whereIn('type', ['terms', 'privacy', 'dpa'])->count()))->toBe(0);
    });

    it('dit qu\'aucune acceptation n\'est enregistrée pour un compte sans historique, sans le bloquer', function (): void {
        [$user] = ($this->account)(withConsents: false);

        $documents = collect(($this->page)('/reglages/juridique')['documents'])->keyBy('slug');

        expect($documents['terms-of-use'])->toMatchArray(['state' => 'missing', 'accepted' => null, 'blocking' => false]);
        ($this->as)()->get('/tableau-de-bord')->assertOk();
    });

    it('le DPA ne concerne que le propriétaire', function (): void {
        [$receptionist] = ($this->account)('receptionist');

        $documents = collect(($this->page)('/reglages/juridique')['documents'])->keyBy('slug');
        expect($documents['dpa']['state'])->toBe('not_applicable');

        ($this->as)()->post('/reglages/juridique/accepter', ['documents' => ['dpa']])->assertSessionHasNoErrors();
        expect(collect(($this->consents)($receptionist, 'dpa'))->count())->toBe(1); // celle d'origine seulement
    });

    it('enregistre une acceptation avec son contexte, une seule fois, et la dit dans l\'historique', function (): void {
        [$user] = ($this->account)(withConsents: false);

        ($this->as)()->withHeader('User-Agent', 'NovaTest/1.0')->post('/reglages/juridique/accepter', ['documents' => ['terms-of-use', 'privacy-policy']])->assertSessionHasNoErrors()->assertSessionHas('status');
        ($this->as)()->post('/reglages/juridique/accepter', ['documents' => ['terms-of-use']])->assertSessionHas('status', 'Rien à enregistrer.');

        $rows = ($this->consents)($user);
        expect(collect($rows)->pluck('type')->all())->toBe(['privacy', 'terms'])->and(collect($rows)->pluck('source')->unique()->all())->toBe(['settings'])
            ->and(collect($rows)->pluck('user_agent')->unique()->all())->toBe(['NovaTest/1.0'])
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'legal.accepted')->where('actor_id', $user->id)->count()))->toBe(2);
    });

    it('refuse un document inconnu ou une demande vide', function (): void {
        ($this->account)();

        ($this->as)()->post('/reglages/juridique/accepter', ['documents' => ['cookies']])->assertSessionHasErrors('documents.0');
        ($this->as)()->post('/reglages/juridique/accepter', [])->assertSessionHasErrors('documents');
    });
});

describe('nouvelle version et nouvelle acceptation', function (): void {
    it('une version sans acceptation requise n\'oblige à rien : simple information', function (): void {
        ($this->account)();
        ($this->publish)('terms', '2026-12-01', null, false);

        $terms = collect(($this->page)('/reglages/juridique')['documents'])->firstWhere('slug', 'terms-of-use');

        expect($terms)->toMatchArray(['state' => 'update', 'blocking' => false, 'version' => '2026-12-01']);
        ($this->as)()->get('/tableau-de-bord')->assertOk();
        expect(($this->page)('/tableau-de-bord')['legal']['pending'])->toBe([]);
    });

    it('une version significative demande une nouvelle acceptation avant de continuer, hors des pages qui protègent les droits de l\'utilisateur', function (): void {
        ($this->account)();
        ($this->publish)('terms', '2026-12-01', null, true, ['fr' => 'CGU 2'], ['fr' => "## 1. Un\nTexte."], ['fr' => 'Nouvelle durée de préavis.']);

        ($this->as)()->get('/tableau-de-bord')->assertRedirect('/reglages/juridique')->assertSessionHas('warning');
        ($this->as)()->get('/planning')->assertRedirect('/reglages/juridique');
        ($this->as)()->get('/clients')->assertRedirect('/reglages/juridique');

        foreach (['/reglages/juridique', '/securite', '/aide', '/compte/donnees', '/facturation', '/reglages/profil'] as $url) {
            expect(($this->as)()->get($url)->status())->toBe(200, $url);
        }

        $terms = collect(($this->page)('/reglages/juridique')['documents'])->firstWhere('slug', 'terms-of-use');
        expect($terms)->toMatchArray(['state' => 'reacceptance', 'blocking' => true, 'changes' => 'Nouvelle durée de préavis.']);
    });

    it('l\'acceptation explicite rouvre le service et garde les deux versions dans l\'historique, contexte « nouvelle acceptation »', function (): void {
        [$user] = ($this->account)();
        ($this->publish)('terms', '2026-12-01');

        ($this->as)()->post('/reglages/juridique/accepter', ['documents' => ['terms-of-use']])->assertSessionHasNoErrors();

        ($this->as)()->get('/tableau-de-bord')->assertOk();
        $rows = collect(($this->consents)($user, 'terms'))->sortBy('document_version')->values();
        expect($rows->pluck('document_version')->all())->toBe(['2026-10-01', '2026-12-01'])->and($rows->pluck('source')->all())->toBe(['signup', 'reacceptance']);

        $history = collect(($this->page)('/reglages/juridique')['documents'])->firstWhere('slug', 'terms-of-use')['history'];
        expect(collect($history)->pluck('version')->all())->toBe(['2026-12-01', '2026-10-01']);
    });

    it('une version significative reste exigée même si une version moins significative est parue depuis', function (): void {
        ($this->account)();
        ($this->publish)('terms', '2026-11-01', now()->subDays(2)->toDateString(), true);
        ($this->publish)('terms', '2026-12-01', now()->subDay()->toDateString(), false);

        $terms = collect(($this->page)('/reglages/juridique')['documents'])->firstWhere('slug', 'terms-of-use');

        expect($terms)->toMatchArray(['state' => 'reacceptance', 'blocking' => true, 'version' => '2026-12-01']);
    });

    it('une version significative pas encore en vigueur n\'oblige à rien', function (): void {
        ($this->account)();
        ($this->publish)('terms', '2027-06-01', now()->addDays(60)->toDateString(), true);

        ($this->as)()->get('/tableau-de-bord')->assertOk();
    });

    it('ne bloque jamais un compte sans aucune acceptation enregistrée (avis simple)', function (): void {
        ($this->account)(withConsents: false);
        ($this->publish)('terms', '2026-12-01');

        ($this->as)()->get('/tableau-de-bord')->assertOk();

        $pending = ($this->page)('/tableau-de-bord')['legal']['pending'];
        expect(collect($pending)->pluck('blocking')->unique()->all())->toBe([false])->and(collect($pending)->pluck('code')->sort()->values()->all())->toBe(['dpa', 'privacy', 'terms']);
    });

    it('partage les documents à accepter avec chaque page, et seulement pour le propriétaire s\'agissant du DPA', function (): void {
        ($this->account)('receptionist', false);

        $pending = ($this->page)('/tableau-de-bord')['legal']['pending'];

        expect(collect($pending)->pluck('code')->sort()->values()->all())->toBe(['privacy', 'terms']);
    });

    it('le DPA significatif ne bloque que le propriétaire', function (): void {
        [$owner, $org] = ($this->account)();
        [$manager] = [null];
        $manager = $this->asOwner(function () use ($org): User {
            $user = User::factory()->create(['email' => 'gestionnaire@example.com']);
            OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => 'site_manager']);
            foreach (['terms', 'privacy', 'dpa'] as $type) {
                DB::table('user_consents')->insert(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'type' => $type, 'document_version' => '2026-10-01', 'granted_at' => now()->subDay(), 'source' => 'signup', 'created_at' => now(), 'updated_at' => now()]);
            }

            return $user;
        });
        ($this->publish)('dpa', '2026-12-01');

        ($this->as)($owner)->get('/tableau-de-bord')->assertRedirect('/reglages/juridique');
        ($this->as)($manager)->get('/tableau-de-bord')->assertOk();
    });
});

describe('publication par le Super Admin', function (): void {
    beforeEach(function (): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@nova.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->admin = $this->admin->refresh();
        $this->panel = fn () => Livewire::actingAs($this->admin, 'platform')->test(ListLegalVersions::class);
        $this->draft = fn (array $override = []): array => [
            'document' => 'terms', 'version' => '2026-12-01', 'effective_on' => now()->addDays(2)->toDateString(), 'requires_acceptance' => true,
            'title_fr' => 'CGU v2', 'body_fr' => "## 1. Un\nTexte.", 'changes_fr' => 'Préavis modifié.', ...$override,
        ];
        $this->record = fn (string $version = '2026-12-01') => PlatformLegalVersion::query()->where('document', 'terms')->where('version', $version)->firstOrFail();
    });

    it('liste les versions publiées avec leur état', function (): void {
        ($this->panel)()->assertSuccessful()->assertCanSeeTableRecords(PlatformLegalVersion::query()->get())->assertSee('2026-10-01')->assertSee('Publiée');
    });

    it('crée un brouillon, qui n\'est pas visible du public et ne change rien tant qu\'il n\'est pas publié', function (): void {
        ($this->panel)()->callAction('create', data: ($this->draft)())->assertHasNoActionErrors();

        $record = ($this->record)();
        expect($record->status)->toBe('draft')->and($record->published_at)->toBeNull()->and($record->title['fr'])->toBe('CGU v2');
        $this->get('/legal/terms-of-use/versions/2026-12-01')->assertNotFound();
        expect(app(LegalDocuments::class)->current('terms')->version)->toBe('2026-10-01');
    });

    it('modifie un brouillon, puis le publie : il devient la version en vigueur à sa date d\'effet', function (): void {
        ($this->panel)()->callAction('create', data: ($this->draft)(['effective_on' => now()->toDateString()]));
        ($this->panel)()->callTableAction('edit', ($this->record)(), data: ($this->draft)(['effective_on' => now()->toDateString(), 'title_fr' => 'CGU v2 corrigées']))->assertHasNoTableActionErrors();
        expect(($this->record)()->title['fr'])->toBe('CGU v2 corrigées');

        ($this->panel)()->callTableAction('publish', ($this->record)());

        $published = ($this->record)();
        expect($published->status)->toBe('published')->and($published->published_at)->not->toBeNull()->and($published->requires_acceptance)->toBeTrue();
        expect(app(LegalDocuments::class)->current('terms')->version)->toBe('2026-12-01');
        $this->get('/legal/terms-of-use')->assertInertia(fn (Assert $page) => $page->where('document.version', '2026-12-01')->where('document.title', 'CGU v2 corrigées'));
    });

    it('une fois publiée, une version ne se modifie ni ne se supprime plus (actions masquées et refus du service)', function (): void {
        ($this->panel)()->callAction('create', data: ($this->draft)(['effective_on' => now()->toDateString()]));
        ($this->panel)()->callTableAction('publish', ($this->record)());
        $published = ($this->record)();

        ($this->panel)()->assertTableActionHidden('edit', $published)->assertTableActionHidden('publish', $published)->assertTableActionHidden('delete', $published);
        expect(fn () => app(LegalVersionManager::class)->update($published, ($this->draft)(), 'admin'))->toThrow(InvalidArgumentException::class, 'published')
            ->and(fn () => app(LegalVersionManager::class)->delete($published, 'admin'))->toThrow(InvalidArgumentException::class, 'published');
    });

    it('supprime un brouillon', function (): void {
        ($this->panel)()->callAction('create', data: ($this->draft)());

        ($this->panel)()->callTableAction('delete', ($this->record)());

        expect(PlatformLegalVersion::query()->where('version', '2026-12-01')->exists())->toBeFalse();
    });

    it('refuse de publier sans texte ou avec une date passée', function (string $code, array $override): void {
        $draft = app(LegalVersionManager::class)->create(($this->draft)($override), (string) $this->admin->getAuthIdentifier());

        expect(fn () => app(LegalVersionManager::class)->publish($draft, (string) $this->admin->getAuthIdentifier()))->toThrow(InvalidArgumentException::class, $code);
        expect($draft->refresh()->status)->toBe('draft');
    })->with([
        'sans texte' => ['no_text', ['title_fr' => '', 'body_fr' => '']],
        'date passée' => ['past_date', ['effective_on' => '2026-10-05']],
    ]);

    it('refuse de publier avec une date d\'effet antérieure ou égale à celle de la dernière version publiée', function (): void {
        ($this->publish)('terms', '2026-11-20', now()->addDays(5)->toDateString());
        $draft = app(LegalVersionManager::class)->create(($this->draft)(['effective_on' => now()->addDays(2)->toDateString()]), (string) $this->admin->getAuthIdentifier());

        expect(fn () => app(LegalVersionManager::class)->publish($draft, (string) $this->admin->getAuthIdentifier()))->toThrow(InvalidArgumentException::class, 'not_after_current');
    });

    it('refuse un doublon de version pour un même document', function (): void {
        ($this->panel)()->callAction('create', data: ($this->draft)(['version' => '2026-10-01']))->assertHasActionErrors(['version']);
    });

    it('trace création, publication et suppression dans le journal d\'audit de la plateforme', function (): void {
        ($this->panel)()->callAction('create', data: ($this->draft)(['effective_on' => now()->toDateString()]));
        ($this->panel)()->callTableAction('publish', ($this->record)());

        $actions = $this->asOwner(fn () => DB::table('audit_logs')->where('actor_type', 'platform_admin')->where('action', 'like', 'legal.%')->orderBy('id')->pluck('action')->all());

        expect($actions)->toBe(['legal.version_created', 'legal.version_published']);
    });

    it('n\'est accessible qu\'au Super Admin : un utilisateur client est renvoyé vers la connexion du panel', function (): void {
        ['user' => $user] = $this->activatedAccount();

        $this->actingAs($user, 'web')->get('/admin/legal-versions')->assertRedirect('/admin/login');
    });
});
