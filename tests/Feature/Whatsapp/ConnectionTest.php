<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Onboarding\Support\OnboardingSteps;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Support\TemplateInfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Connexion du numéro WhatsApp et modèles de messages (étapes 7 et 8 de l'onboarding, erreurs E5 et E6).
 */
const WA_PASSWORD = 'password-de-test';
const WA_TOKEN = 'EAAGm0PX4ZCpsBAKtoken-de-test-1234567890';

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore']);
    $this->transport = app(WhatsappTransport::class);
    RateLimiter::clear('whatsapp-password:'.$this->owner->id);

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->connect = fn (array $overrides = []) => ($this->as)()->post('/whatsapp', ['phone_number_id' => '104500000000001', 'waba_id' => '204500000000002', 'access_token' => WA_TOKEN, 'password' => WA_PASSWORD, ...$overrides]);
    $this->account = fn () => DB::connection('pgsql_owner')->table('whatsapp_accounts')->where('org_id', $this->org->id)->first();
    $this->templates = fn () => DB::connection('pgsql_owner')->table('whatsapp_templates')->where('org_id', $this->org->id)->get();
});

describe('page', function (): void {
    it('affiche l\'état au propriétaire', function (): void {
        ($this->as)()->get('/whatsapp')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Account/Whatsapp')->where('account', null)->where('templates', [])->where('allApproved', false));
    });

    it('est réservée au propriétaire', function (string $role): void {
        ['user' => $member, 'org' => $memberOrg] = $this->activatedAccount(email: "{$role}@example.com", role: $role);

        $this->signedIn($member, $memberOrg)->get('/whatsapp')->assertForbidden();
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('exige d\'être connecté', function (): void {
        $this->get('/whatsapp')->assertRedirect('/connexion');
    });
});

describe('connexion du numéro', function (): void {
    it('vérifie auprès de Meta, enregistre le compte avec un jeton chiffré et trace', function (): void {
        ($this->connect)()->assertSessionHasNoErrors()->assertSessionHas('status', 'WhatsApp est connecté.');

        $account = ($this->account)();
        expect($account)->phone_number_id->toBe('104500000000001')->waba_id->toBe('204500000000002')->display_phone->toBe('+32 470 12 34 56')->status->toBe('active')
            ->and($account->access_token_enc)->not->toContain('EAAGm0PX4ZCpsBAK')
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'whatsapp.connected')->count()))->toBe(1);
    });

    it('ne renvoie jamais le jeton à la page', function (): void {
        ($this->connect)();

        $response = ($this->as)()->get('/whatsapp')->assertInertia(fn (Assert $page) => $page->where('account.phone_number_id', '104500000000001')->where('account.display_phone', '+32 470 12 34 56'));

        expect($response->getContent())->not->toContain('EAAGm0PX4ZCpsBAK');
    });

    it('refuse des identifiants que Meta n\'accepte pas (E5) sans rien enregistrer', function (): void {
        $this->transport->failVerifyWith = 'meta_190';

        ($this->connect)()->assertSessionHasErrors(['whatsapp' => 'Meta n\'a pas accepté ces identifiants : vérifiez l\'identifiant du numéro, celui du compte WhatsApp Business et le jeton.']);

        expect(($this->account)())->toBeNull();
    });

    it('signale Meta injoignable', function (): void {
        $this->transport->failVerifyWith = 'network';

        ($this->connect)()->assertSessionHasErrors(['whatsapp' => 'Meta est injoignable pour le moment. Réessayez dans quelques minutes.']);

        expect(($this->account)())->toBeNull();
    });

    it('refuse un numéro déjà connecté à un autre établissement', function (): void {
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');
        $this->asOwner(fn () => WhatsappAccount::query()->create(['org_id' => $other->id, 'phone_number_id' => '104500000000001', 'access_token_enc' => 'x']));

        ($this->connect)()->assertSessionHasErrors(['whatsapp' => 'Ce numéro est déjà connecté à un autre établissement.']);

        expect(($this->account)())->toBeNull();
    });

    it('refuse un mauvais mot de passe et des champs invalides', function (array $override, string $field): void {
        ($this->connect)($override)->assertSessionHasErrors($field);

        expect(($this->account)())->toBeNull();
    })->with([
        'mauvais mot de passe' => [['password' => 'nope'], 'password'],
        'numéro non numérique' => [['phone_number_id' => 'abc'], 'phone_number_id'],
        'compte absent' => [['waba_id' => ''], 'waba_id'],
        'jeton trop court' => [['access_token' => 'court'], 'access_token'],
    ]);

    it('bloque après 5 mots de passe faux', function (): void {
        foreach (range(1, 5) as $ignored) {
            ($this->connect)(['password' => 'nope']);
        }

        ($this->connect)()->assertSessionHasErrors('password');
        expect(($this->account)())->toBeNull();
    });

    it('remplace le numéro précédent : un seul numéro actif', function (): void {
        ($this->connect)();
        ($this->connect)(['phone_number_id' => '104500000000099'])->assertSessionHasNoErrors();

        expect(DB::connection('pgsql_owner')->table('whatsapp_accounts')->where('org_id', $this->org->id)->pluck('phone_number_id')->all())->toBe(['104500000000099']);
    });

    it('permet de reconnecter le même numéro avec un nouveau jeton', function (): void {
        ($this->connect)();
        ($this->connect)(['access_token' => 'EAAGnouveau-jeton-1234567890-abcdef'])->assertSessionHasNoErrors();

        expect(DB::connection('pgsql_owner')->table('whatsapp_accounts')->where('org_id', $this->org->id)->count())->toBe(1);
        $this->asOwner(fn () => expect(WhatsappAccount::query()->sole()->access_token_enc)->toBe('EAAGnouveau-jeton-1234567890-abcdef'));
    });
});

describe('déconnexion', function (): void {
    it('supprime le numéro, le jeton et le suivi des modèles, et trace', function (): void {
        ($this->connect)();
        ($this->as)()->post('/whatsapp/modeles');

        ($this->as)()->delete('/whatsapp', ['password' => WA_PASSWORD])->assertSessionHas('status', 'Le numéro est déconnecté.');

        expect(($this->account)())->toBeNull()->and(($this->templates)())->toHaveCount(0)
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'whatsapp.disconnected')->count()))->toBe(1);
    });

    it('exige le mot de passe', function (): void {
        ($this->connect)();

        ($this->as)()->delete('/whatsapp', ['password' => 'nope'])->assertSessionHasErrors('password');

        expect(($this->account)())->not->toBeNull();
    });
});

describe('modèles de messages', function (): void {
    it('refuse de soumettre ou d\'actualiser sans numéro connecté', function (): void {
        ($this->as)()->post('/whatsapp/modeles')->assertSessionHasErrors(['whatsapp' => 'Connectez d\'abord votre numéro WhatsApp.']);
        ($this->as)()->post('/whatsapp/modeles/synchroniser')->assertSessionHasErrors('whatsapp');
    });

    it('soumet les 12 modèles (4 messages en fr, nl et en) avec leurs textes et exemples', function (): void {
        ($this->connect)();

        ($this->as)()->post('/whatsapp/modeles')->assertSessionHas('status', '12 message(s) type(s) soumis à Meta. L\'approbation prend en général quelques heures.');

        expect($this->transport->created)->toHaveCount(12)->and(($this->templates)())->toHaveCount(12)->and(($this->templates)()->pluck('status')->unique()->all())->toBe(['pending']);

        $fr = collect($this->transport->created)->firstWhere(fn (array $c): bool => $c['name'] === 'appointment_cancelled' && $c['language'] === 'fr');
        expect($fr['body'])->toContain('{{1}}')->toContain('{{4}}')->toContain('annuler votre rendez-vous')
            ->and($fr['examples'])->toBe(['Salon Aurore', 'Coupe', 'mercredi 3 juin', '10:00'])
            ->and(collect($this->transport->created)->pluck('name')->unique()->sort()->values()->all())->toBe(['appointment_cancelled', 'appointment_confirmation', 'appointment_reminder', 'appointment_rescheduled']);
    });

    it('ne soumet qu\'une fois chaque modèle', function (): void {
        ($this->connect)();
        ($this->as)()->post('/whatsapp/modeles');

        ($this->as)()->post('/whatsapp/modeles')->assertSessionHas('status', 'Tous les messages types ont déjà été soumis.');

        expect($this->transport->created)->toHaveCount(12);
    });

    it('signale les modèles que Meta n\'a pas pu recevoir et en soumet le reste plus tard', function (): void {
        ($this->connect)();
        $this->transport->failWith = 'meta_100';

        ($this->as)()->post('/whatsapp/modeles')->assertSessionHasErrors(['whatsapp' => '12 message(s) type(s) n\'ont pas pu être soumis. Réessayez dans quelques minutes.']);
        expect(($this->templates)())->toHaveCount(0);

        $this->transport->failWith = null;
        ($this->as)()->post('/whatsapp/modeles')->assertSessionHasNoErrors();
        expect(($this->templates)())->toHaveCount(12);
    });

    it('met à jour les statuts depuis Meta, avec le motif d\'un refus', function (): void {
        ($this->connect)();
        ($this->as)()->post('/whatsapp/modeles');
        $this->transport->library = array_map(fn (TemplateInfo $info): TemplateInfo => match (true) {
            $info->name === 'appointment_reminder' && $info->language === 'fr' => TemplateInfo::fromMeta($info->name, $info->language, 'REJECTED', 'INVALID_FORMAT', $info->metaId),
            $info->name === 'appointment_reminder' => TemplateInfo::fromMeta($info->name, $info->language, 'PAUSED', 'LOW_QUALITY', $info->metaId),
            default => TemplateInfo::fromMeta($info->name, $info->language, 'APPROVED', null, $info->metaId),
        }, $this->transport->library);
        $this->transport->library[] = new TemplateInfo('un_autre_modele', 'fr', 'approved');

        ($this->as)()->post('/whatsapp/modeles/synchroniser')->assertSessionHas('status', 'Statuts actualisés.');

        $rows = ($this->templates)()->keyBy(fn ($row): string => $row->name.'|'.$row->language);
        expect($rows['appointment_reminder|fr'])->status->toBe('rejected')->rejection_reason->toBe('INVALID_FORMAT')
            ->and($rows['appointment_reminder|nl'])->status->toBe('paused')
            ->and($rows['appointment_confirmation|en'])->status->toBe('approved')->rejection_reason->toBeNull()
            ->and($rows)->toHaveCount(12);

        ($this->as)()->get('/whatsapp')->assertInertia(fn (Assert $page) => $page->where('allApproved', false)->has('templates', 12));
    });

    it('indique que tout est approuvé', function (): void {
        ($this->connect)();
        ($this->as)()->post('/whatsapp/modeles');
        $this->transport->library = array_map(fn (TemplateInfo $info): TemplateInfo => new TemplateInfo($info->name, $info->language, 'approved', null, $info->metaId), $this->transport->library);

        ($this->as)()->post('/whatsapp/modeles/synchroniser');

        ($this->as)()->get('/whatsapp')->assertInertia(fn (Assert $page) => $page->where('allApproved', true));
    });

    it('signale Meta injoignable à l\'actualisation', function (): void {
        ($this->connect)();
        $this->transport->failWith = 'network';

        ($this->as)()->post('/whatsapp/modeles/synchroniser')->assertSessionHasErrors(['whatsapp' => 'Meta est injoignable pour le moment. Réessayez dans quelques minutes.']);
        $this->transport->failWith = null;
    });

    it('n\'expose pas les modèles d\'un autre établissement', function (): void {
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');
        $this->asOwner(fn () => WhatsappTemplate::query()->create(['org_id' => $other->id, 'name' => 'appointment_reminder', 'language' => 'fr', 'status' => 'approved']));
        ($this->connect)();

        ($this->as)()->get('/whatsapp')->assertInertia(fn (Assert $page) => $page->where('templates.0.status', 'missing'));
    });

    it('est réservé au propriétaire', function (): void {
        ['user' => $manager, 'org' => $managerOrg] = $this->activatedAccount(email: 'resp@example.com', role: 'site_manager');

        $this->signedIn($manager, $managerOrg)->post('/whatsapp/modeles')->assertForbidden();
        $this->signedIn($manager, $managerOrg)->post('/whatsapp', ['phone_number_id' => '1', 'waba_id' => '1', 'access_token' => WA_TOKEN, 'password' => WA_PASSWORD])->assertForbidden();
    });
});

describe('synchronisation horaire (whatsapp:sync-templates)', function (): void {
    it('actualise les statuts des établissements connectés et ignore les autres', function (): void {
        ($this->connect)();
        ($this->as)()->post('/whatsapp/modeles');
        $this->transport->library = array_map(fn (TemplateInfo $info): TemplateInfo => new TemplateInfo($info->name, $info->language, 'approved', null, $info->metaId), $this->transport->library);
        $this->activatedAccount(['status' => 'active'], 'sans-whatsapp@example.com');

        $this->artisan('whatsapp:sync-templates')->expectsOutputToContain('Statuts modifiés : 12')->assertSuccessful();

        expect(($this->templates)()->pluck('status')->unique()->all())->toBe(['approved']);
        $this->artisan('whatsapp:sync-templates')->expectsOutputToContain('Statuts modifiés : 0')->assertSuccessful();
    });

    it('n\'est pas arrêtée par une organisation en erreur', function (): void {
        ($this->connect)();
        $this->transport->failWith = 'meta_190';

        $this->artisan('whatsapp:sync-templates')->expectsOutputToContain('organisations en erreur : 1')->assertSuccessful();
        $this->transport->failWith = null;
    });

    it('ne traite pas un compte suspendu', function (): void {
        ($this->connect)();
        $this->asOwner(fn () => Organization::query()->whereKey($this->org->id)->update(['status' => 'suspended']));

        $this->artisan('whatsapp:sync-templates')->expectsOutputToContain('Statuts modifiés : 0')->assertSuccessful();
    });
});

describe('assistant d\'onboarding', function (): void {
    beforeEach(function (): void {
        $this->asOwner(fn () => DB::table('onboarding_progress')->where('org_id', $this->org->id)->update(['steps' => json_encode(array_fill_keys([OnboardingSteps::COMPANY, OnboardingSteps::LOCATIONS, OnboardingSteps::HOURS, OnboardingSteps::SERVICES, OnboardingSteps::PRACTITIONERS, OnboardingSteps::POLICIES], 'done')), 'completed_at' => null]));
    });

    it('propose la connexion à l\'étape 7 et les modèles à l\'étape 8', function (): void {
        ($this->as)()->get('/configuration/assistant/whatsapp')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Onboarding/Whatsapp')->where('account', null));
        ($this->as)()->get('/configuration/assistant/messages-types')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Onboarding/Templates'));
    });

    it('autorise de continuer sans attendre Meta, étape après étape', function (): void {
        ($this->as)()->post('/configuration/assistant/whatsapp/continuer')->assertRedirect('/configuration/assistant/messages-types');
        ($this->as)()->post('/configuration/assistant/messages-types/continuer')->assertRedirect('/configuration/assistant/reservation-test');

        $steps = json_decode(DB::connection('pgsql_owner')->table('onboarding_progress')->where('org_id', $this->org->id)->value('steps'), true);
        expect($steps)->toHaveKeys([OnboardingSteps::WHATSAPP, OnboardingSteps::TEMPLATES]);
    });

    it('est réservé au propriétaire', function (): void {
        ['user' => $manager, 'org' => $managerOrg] = $this->activatedAccount(email: 'resp@example.com', role: 'site_manager');

        $this->signedIn($manager, $managerOrg)->post('/configuration/assistant/whatsapp/continuer')->assertForbidden();
    });
});

describe('tableau de bord', function (): void {
    it('compte la connexion WhatsApp dans les premiers pas', function (): void {
        ($this->as)()->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('firstSteps.0', ['key' => 'whatsapp', 'done' => false]));

        ($this->connect)();

        ($this->as)()->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('firstSteps.0', ['key' => 'whatsapp', 'done' => true]));
    });
});

describe('contrôle d\'un compte de test (whatsapp:check)', function (): void {
    it('valide le numéro, liste les modèles de Rendivoo et n\'écrit rien', function (): void {
        $this->transport->library = [new TemplateInfo('appointment_reminder', 'fr', 'approved')];

        $this->artisan('whatsapp:check', ['phone_number_id' => '104500000000001', 'waba_id' => '204500000000002'])
            ->expectsQuestion('Jeton d\'accès Meta', WA_TOKEN)
            ->expectsOutputToContain('Numéro valide : +32 470 12 34 56')
            ->expectsOutputToContain('appointment_reminder')
            ->assertSuccessful();

        expect(DB::connection('pgsql_owner')->table('whatsapp_accounts')->count())->toBe(0)->and($this->transport->created)->toBe([]);
    });

    it('signale un refus de Meta et un jeton vide', function (): void {
        $this->transport->failVerifyWith = 'meta_190';

        $this->artisan('whatsapp:check', ['phone_number_id' => '1', 'waba_id' => '2'])->expectsQuestion('Jeton d\'accès Meta', WA_TOKEN)->expectsOutputToContain('meta_190')->assertFailed();
        $this->artisan('whatsapp:check', ['phone_number_id' => '1', 'waba_id' => '2'])->expectsQuestion('Jeton d\'accès Meta', '')->expectsOutputToContain('Jeton vide.')->assertFailed();
        $this->transport->failVerifyWith = null;
    });
});
