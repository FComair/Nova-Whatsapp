<?php

declare(strict_types=1);

use App\Modules\Api\Models\ApiClient;
use App\Modules\Api\Services\ApiClientService;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Services\LoyaltyEngine;
use App\Modules\Loyalty\Services\LoyaltyPrograms;
use App\Modules\Loyalty\Services\LoyaltyRedemptions;
use App\Modules\Tenancy\Models\OrgMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;
use Tests\Support\LoyaltyScenarios;

uses(AuthScenarios::class, BookingScenarios::class, LoyaltyScenarios::class);

/**
 * Écrans de fidélité : droits par rôle, configuration guidée (brouillon, activation, aperçu de l'effet d'un changement), utilisation et annulation
 * d'une récompense, liste des clients, fiche client, rendez-vous, rapport, API du bot. Lundi 1er juin 2026 ; visites terminées le 5 juin.
 */
beforeEach(function (): void {
    $this->loyaltyWorld(3);

    $this->staff = fn (string $role): User => $this->asOwner(function () use ($role): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);

        if ($role === 'practitioner') {
            $this->practitioner->forceFill(['user_id' => $user->id])->save();
        }

        return $user;
    });
    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->programInput = fn (array $override = []): array => [
        'name' => ['fr' => 'Fidélité', 'nl' => '', 'en' => 'Loyalty'],
        'reward_description' => ['fr' => 'Une coupe offerte', 'nl' => '', 'en' => 'A free haircut'],
        'terms' => ['fr' => 'Non cumulable.', 'nl' => '', 'en' => ''],
        'required_visits' => 3, 'services_mode' => 'all', 'min_amount' => '', 'require_payment' => false, 'auto_enroll' => true,
        'reward_type' => 'free_service', 'reward_services_mode' => 'all', 'redeem_timing' => 'next_visit', 'combinable' => false, 'requires_appointment' => true,
        'expiry_days' => '', 'expiry_reminder_days' => '14',
        ...$override,
    ];
    $this->props = fn (string $url, ?User $user = null): array => Assert::fromTestResponse(($this->as)($user)->get($url)->assertOk())->toArray()['props'];
});

describe('droits par rôle', function (): void {
    it('réserve la configuration au propriétaire, la lecture et l\'utilisation au personnel d\'accueil, et exclut le praticien', function (): void {
        ($this->program)();
        $roles = ['owner' => $this->owner, 'site_manager' => ($this->staff)('site_manager'), 'receptionist' => ($this->staff)('receptionist'), 'practitioner' => ($this->staff)('practitioner')];
        $matrix = [
            '/clients/fidelite' => ['owner' => 200, 'site_manager' => 200, 'receptionist' => 200, 'practitioner' => 403],
            '/clients/fidelite/clients' => ['owner' => 200, 'site_manager' => 200, 'receptionist' => 200, 'practitioner' => 403],
            '/clients/fidelite/utiliser' => ['owner' => 200, 'site_manager' => 200, 'receptionist' => 200, 'practitioner' => 403],
            '/clients/fidelite/rapport' => ['owner' => 200, 'site_manager' => 200, 'receptionist' => 200, 'practitioner' => 403],
            '/clients/fidelite/programme' => ['owner' => 200, 'site_manager' => 403, 'receptionist' => 403, 'practitioner' => 403],
            '/clients/fidelite/messages' => ['owner' => 200, 'site_manager' => 403, 'receptionist' => 403, 'practitioner' => 403],
        ];

        foreach ($matrix as $url => $expected) {
            foreach ($expected as $role => $status) {
                expect(($this->as)($roles[$role])->get($url)->status())->toBe($status, "{$role} sur {$url}");
            }
        }
    });

    it('exige d\'être connecté', function (): void {
        $this->get('/clients/fidelite')->assertRedirect('/connexion');
        $this->post('/clients/fidelite/programme/activer')->assertRedirect('/connexion');
    });

    it('limite les actions : ajuster et annuler une utilisation au propriétaire et au responsable de site, utiliser aussi à la réceptionniste', function (): void {
        ($this->program)(['required_visits' => 1]);
        $target = ($this->book)();
        ($this->visit)();
        [$reward] = ($this->rewards)('available');
        $receptionist = ($this->staff)('receptionist');
        $practitioner = ($this->staff)('practitioner');
        $manager = ($this->staff)('site_manager');
        $adjust = ['delta' => 1, 'reason' => 'Correction'];

        ($this->as)($receptionist)->post("/clients/fidelite/membres/{$this->customer->id}/ajuster", $adjust)->assertForbidden();
        ($this->as)($practitioner)->post("/clients/fidelite/membres/{$this->customer->id}/ajuster", $adjust)->assertForbidden();
        ($this->as)($practitioner)->post("/clients/fidelite/recompenses/{$reward->id}/utiliser", ['booking_id' => $target->id])->assertForbidden();
        ($this->as)($receptionist)->post("/clients/fidelite/recompenses/{$reward->id}/utiliser", ['booking_id' => $target->id])->assertSessionHasNoErrors();
        ($this->as)($receptionist)->post("/clients/fidelite/recompenses/{$reward->id}/annuler", ['reason' => 'Erreur'])->assertForbidden();
        ($this->as)($manager)->post("/clients/fidelite/recompenses/{$reward->id}/annuler", ['reason' => 'Erreur de client'])->assertSessionHasNoErrors();
        ($this->as)($manager)->post("/clients/fidelite/membres/{$this->customer->id}/ajuster", $adjust)->assertSessionHasNoErrors();
    });
});

describe('tableau de bord', function (): void {
    it('affiche un état vide tant que le programme n\'est pas configuré', function (): void {
        ($this->as)()->get('/clients/fidelite')->assertInertia(fn (Assert $page) => $page->component('Loyalty/Index')->where('program', null)->where('overview', null)->where('can.configure', true));
        ($this->as)(($this->staff)('receptionist'))->get('/clients/fidelite')->assertInertia(fn (Assert $page) => $page->where('program', null)->where('can.configure', false));
    });

    it('donne les chiffres, les clients proches de leur récompense et l\'activité récente', function (): void {
        ($this->program)(['required_visits' => 3]);
        $near = ($this->customerWith)('+32470111000', 'Proche');
        ($this->visit)($near);
        ($this->visit)($near);
        ($this->visit)();
        ($this->visit)();
        ($this->visit)();

        $props = ($this->props)('/clients/fidelite');

        expect($props['program']['status'])->toBe('active')->and($props['program']['name'])->toBe('Fidélité')
            ->and($props['overview'])->toMatchArray(['enrolled' => 2, 'in_progress' => 1, 'rewards_earned' => 1, 'rewards_redeemed' => 0, 'rewards_outstanding' => 1, 'rewards_expired' => 0])
            ->and($props['overview']['close'])->toHaveCount(1)->and($props['overview']['close'][0])->toMatchArray(['customer_id' => $near->id, 'stamps' => 2, 'remaining' => 1])
            ->and(collect($props['recent'])->pluck('kind')->unique()->all())->toContain('stamp_earned', 'reward_issued', 'enrolled');
    });

    it('la liste des clients donne progression, récompenses, dernière visite et statut, avec filtre et recherche', function (): void {
        ($this->program)(['required_visits' => 3]);
        $ready = ($this->customerWith)('+32470111001', 'Prête');
        $started = ($this->customerWith)('+32470111002', 'Débutante');
        ($this->customerWith)('+32470111003', 'Nouveau');
        foreach (range(1, 3) as $_) {
            ($this->visit)($ready);
        }
        ($this->visit)($started);

        $rows = fn (string $query = ''): array => collect(($this->props)("/clients/fidelite/clients{$query}")['customers'])->keyBy('name')->all();
        $all = $rows();

        expect($all['Prête'])->toMatchArray(['status' => 'reward_available', 'rewards_available' => 1, 'stamps' => 0, 'required' => 3])
            ->and($all['Débutante'])->toMatchArray(['status' => 'in_progress', 'stamps' => 1])->and($all['Débutante']['last_visit_at'])->not->toBeNull()
            ->and($all['Nouveau'])->toMatchArray(['status' => 'not_enrolled', 'stamps' => null, 'last_visit_at' => null])
            ->and(array_keys($rows('?status=reward_available')))->toBe(['Prête'])->and(array_keys($rows('?status=not_enrolled')))->toContain('Nouveau')->not->toContain('Prête')
            ->and(array_keys($rows('?q=débu')))->toBe(['Débutante'])->and(array_keys($rows('?q=0111003')))->toBe(['Nouveau']);
    });

    it('classe « utilisée » après usage et « inactif » après départ ou longue absence', function (): void {
        ($this->program)(['required_visits' => 1]);
        $used = ($this->customerWith)('+32470111004', 'Utilisée');
        $left = ($this->customerWith)('+32470111005', 'Partie');
        $target = ($this->book)($used);
        ($this->visit)($used);
        ($this->in)(fn () => app(LoyaltyRedemptions::class)->redeem(($this->rewards)('available')[0], $target, $this->owner->id));
        ($this->visit)($left);
        ($this->in)(fn () => app(LoyaltyEngine::class)->optOut($left, $this->owner->id));
        $this->asOwner(fn () => DB::table('loyalty_members')->where('customer_id', $used->id)->update(['stamps' => 0]));

        $rows = collect(($this->props)('/clients/fidelite/clients')['customers'])->keyBy('name');

        expect($rows['Utilisée']['status'])->toBe('redeemed')->and($rows['Partie']['status'])->toBe('inactive');
    });
});

describe('configuration', function (): void {
    it('enregistre un brouillon incomplet sans l\'activer, et dit ce qui manque', function (): void {
        ($this->as)()->put('/clients/fidelite/programme', ($this->programInput)(['name' => ['fr' => '', 'nl' => '', 'en' => ''], 'terms' => ['fr' => '']]))->assertSessionHasNoErrors();

        $props = ($this->props)('/clients/fidelite/programme');

        expect($props['program']['status'])->toBe('draft')->and($props['problems'])->toBe(['name', 'terms'])->and($props['values']['required_visits'])->toBe(3);

        ($this->as)()->post('/clients/fidelite/programme/activer')->assertSessionHasErrors(['program' => 'Le programme est incomplet : corrigez les points signalés avant de l’activer.']);
        expect(($this->in)(fn () => LoyaltyProgram::query()->value('status')))->toBe('draft');
    });

    it('active un programme valide et le met en pause, avec l\'audit', function (): void {
        ($this->as)()->put('/clients/fidelite/programme', ($this->programInput)())->assertSessionHasNoErrors();
        ($this->as)()->post('/clients/fidelite/programme/activer')->assertSessionHasNoErrors()->assertSessionHas('status');
        expect(($this->in)(fn () => LoyaltyProgram::query()->value('status')))->toBe('active');

        ($this->as)()->post('/clients/fidelite/programme/pause')->assertSessionHasNoErrors();
        expect(($this->in)(fn () => LoyaltyProgram::query()->value('status')))->toBe('paused')
            ->and(($this->in)(fn () => AuditLog::query()->whereIn('action', ['loyalty.program_active', 'loyalty.program_paused'])->count()))->toBe(2);
    });

    it('convertit les montants en euros en centimes et valide les valeurs', function (): void {
        ($this->as)()->put('/clients/fidelite/programme', ($this->programInput)(['min_amount' => '12,50', 'reward_type' => 'fixed_amount', 'reward_amount' => '5', 'reward_max' => '7,5', 'expiry_days' => '30']))->assertSessionHasNoErrors();

        $program = ($this->in)(fn () => LoyaltyProgram::query()->firstOrFail());

        expect($program->min_amount_cents)->toBe(1250)->and($program->reward_type)->toBe('fixed_amount')->and($program->reward_value)->toBe(500)->and($program->reward_max_cents)->toBe(750)->and($program->expiry_days)->toBe(30);

        foreach ([['required_visits' => 0], ['required_visits' => 101], ['min_amount' => 'abc'], ['reward_type' => 'percent_discount', 'reward_percent' => 150], ['expiry_days' => 900], ['name' => ['fr' => str_repeat('a', 81)]]] as $bad) {
            ($this->as)()->put('/clients/fidelite/programme', ($this->programInput)($bad))->assertSessionHasErrors();
        }
    });

    it('présente l\'effet d\'un changement avant de l\'appliquer, puis l\'applique sur confirmation', function (): void {
        ($this->program)(['required_visits' => 4]);
        ($this->in)(fn () => app(LoyaltyEngine::class)->adjust($this->customer, 3, 'Carte reprise', $this->owner->id));

        ($this->as)()->put('/clients/fidelite/programme', ($this->programInput)(['required_visits' => 2]))->assertSessionHasNoErrors()->assertRedirect('/clients/fidelite/programme')->assertSessionHas('loyalty_impact');
        expect(($this->in)(fn () => LoyaltyProgram::query()->value('required_visits')))->toBe(4);

        $page = ($this->props)('/clients/fidelite/programme');
        expect($page['impact'])->toMatchArray(['members' => 1, 'required_from' => 4, 'required_to' => 2, 'rewards_now' => 1]);

        ($this->as)()->put('/clients/fidelite/programme', ($this->programInput)(['required_visits' => 2, 'confirmed' => true]))->assertSessionHasNoErrors();
        expect(($this->in)(fn () => LoyaltyProgram::query()->value('required_visits')))->toBe(2)->and(($this->rewards)('available'))->toHaveCount(1);
    });

    it('un programme archivé laisse créer un nouveau programme', function (): void {
        ($this->program)();
        ($this->as)()->post('/clients/fidelite/programme/archiver')->assertSessionHasNoErrors();

        $props = ($this->props)('/clients/fidelite/programme');
        expect($props['program']['status'])->toBe('archived')->and($props['values']['name'])->toBe(['fr' => '', 'nl' => '', 'en' => '']);

        ($this->as)()->put('/clients/fidelite/programme', ($this->programInput)())->assertSessionHasNoErrors();
        expect(($this->in)(fn () => LoyaltyProgram::query()->count()))->toBe(2)->and(($this->in)(fn () => LoyaltyProgram::current()?->status))->toBe('draft');
    });

    it('fournit l\'aperçu client dans chaque langue', function (): void {
        $props = ($this->props)('/clients/fidelite/programme');

        expect(array_keys($props['preview_i18n']))->toBe(['fr', 'nl', 'en'])->and($props['preview_i18n']['nl']['of'])->toBe(':stamps van :required bezoeken')->and($props['preview_i18n']['en']['marketing'])->toContain('marketing');
    });

    it('ne laisse un propriétaire modifier que son propre établissement', function (): void {
        ($this->program)();
        ['user' => $other, 'org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com');

        $this->signedIn($other, $otherOrg)->get('/clients/fidelite')->assertInertia(fn (Assert $page) => $page->where('program', null));
    });
});

describe('messages WhatsApp', function (): void {
    it('liste cinq messages désactivés au départ, avec leurs variables et textes proposés', function (): void {
        ($this->program)();

        $props = ($this->props)('/clients/fidelite/messages');

        expect($props['kinds'])->toBe(['joined', 'progress', 'reward_earned', 'reward_redeemed', 'expiry_reminder'])
            ->and(collect($props['settings'])->pluck('enabled')->unique()->all())->toBe([false])
            ->and($props['variables'])->toContain('name', 'progress', 'reward', 'expiry_date')
            ->and($props['defaults']['progress']['fr'])->toContain('{progress}')->and($props['samples']['en']['name'])->toBe('Camille');
    });

    it('enregistre les réglages, refuse une variable inconnue, et journalise', function (): void {
        ($this->program)();
        $settings = fn (string $text) => ['settings' => collect(['joined', 'progress', 'reward_earned', 'reward_redeemed', 'expiry_reminder'])->mapWithKeys(fn (string $kind): array => [$kind => ['enabled' => $kind === 'progress', 'texts' => ['fr' => $kind === 'progress' ? $text : '', 'nl' => '', 'en' => '']]])->all()];

        ($this->as)()->put('/clients/fidelite/messages', $settings('Bonjour {prenom}'))->assertSessionHasErrors('settings');
        ($this->as)()->put('/clients/fidelite/messages', $settings('Merci {name} : {progress}'))->assertSessionHasNoErrors();

        $saved = ($this->in)(fn () => LoyaltyProgram::query()->firstOrFail()->messages);
        expect($saved['progress'])->toMatchArray(['enabled' => true])->and($saved['progress']['texts']['fr'])->toBe('Merci {name} : {progress}')->and($saved['joined']['enabled'])->toBeFalse()
            ->and(($this->in)(fn () => AuditLog::query()->where('action', 'loyalty.messages_updated')->count()))->toBe(1);
    });

    it('ne la laisse ouvrir qu\'au propriétaire', function (): void {
        ($this->program)();

        ($this->as)(($this->staff)('site_manager'))->put('/clients/fidelite/messages', ['settings' => []])->assertForbidden();
    });
});

describe('fiche client et rendez-vous', function (): void {
    it('montre la progression, les récompenses et l\'historique sur la fiche, à tous les rôles qui voient le client', function (): void {
        ($this->program)(['required_visits' => 3]);
        ($this->visit)();
        ($this->visit)();

        $owner = ($this->props)('/clients/'.$this->customer->id)['loyalty'];
        $practitioner = ($this->props)('/clients/'.$this->customer->id, ($this->staff)('practitioner'))['loyalty'];

        expect($owner['panel']['member'])->toMatchArray(['stamps' => 2, 'required' => 3, 'remaining' => 1, 'status' => 'active'])->and($owner['can'])->toMatchArray(['configure' => true, 'redeem' => true, 'adjust' => true])
            ->and($owner['panel']['history'])->not->toBeEmpty()->and($owner['configured'])->toBeTrue()
            ->and($practitioner['can'])->toMatchArray(['configure' => false, 'redeem' => false, 'adjust' => false])->and($practitioner['bookings'])->toBe([]);
    });

    it('sans programme, la fiche ne propose la fidélité qu\'au propriétaire', function (): void {
        expect(($this->props)('/clients/'.$this->customer->id)['loyalty']['configured'])->toBeFalse();

        $this->asOwner(fn () => DB::table('loyalty_programs')->delete());
        expect(($this->props)('/clients/'.$this->customer->id, ($this->staff)('receptionist'))['loyalty'])->toBeNull();
    });

    it('le planning indique la progression, le tampon gagné et la récompense appliquée sur le rendez-vous', function (): void {
        ($this->program)(['required_visits' => 1]);
        $target = ($this->book)();
        $earning = ($this->visit)();
        [$reward] = ($this->rewards)('available');
        ($this->in)(fn () => app(LoyaltyRedemptions::class)->redeem($reward, $target, $this->owner->id));
        $this->travelTo(CarbonImmutable::parse('2026-06-05 12:00:00', 'UTC'));

        $rows = collect(($this->props)("/planning?location={$this->location->id}&date=2026-06-02&view=week")['bookings'])->keyBy('id');

        expect($rows[$earning->id]['loyalty'])->toMatchArray(['earned_here' => true, 'enrolled' => true, 'required' => 1])
            ->and($rows[$target->id]['loyalty']['reward_applied_cents'])->toBe(2500)
            ->and($rows[$target->id]['price_cents'])->toBe(2500)->and($rows[$target->id]['amount_collected_cents'])->toBe(0);
    });
});

describe('utiliser une récompense', function (): void {
    it('guide la recherche, la vérification d\'identité, les conditions et la confirmation', function (): void {
        ($this->program)(['required_visits' => 1, 'terms' => ['fr' => 'Une seule fois.', 'nl' => '', 'en' => '']]);
        $target = ($this->book)();
        ($this->visit)();

        $found = ($this->props)('/clients/fidelite/utiliser?q=Client');
        expect($found['results'])->toHaveCount(1)->and($found['results'][0])->toMatchArray(['customer_id' => $this->customer->id, 'rewards' => 1]);

        $page = ($this->props)("/clients/fidelite/utiliser?customer={$this->customer->id}");
        expect($page['customer']['phone'])->toBe('+32470123456')->and($page['panel']['rewards'][0])->toMatchArray(['status' => 'available', 'type' => 'free_service', 'requires_appointment' => true, 'combinable' => false])
            ->and($page['panel']['rewards'][0]['terms'])->toBe('Une seule fois.')->and(collect($page['bookings'])->pluck('id')->all())->toContain($target->id);

        ($this->as)()->post('/clients/fidelite/recompenses/'.$page['panel']['rewards'][0]['id'].'/utiliser', ['booking_id' => $target->id])->assertSessionHas('loyalty_redeemed')->assertSessionHasNoErrors();
        $done = ($this->props)("/clients/fidelite/utiliser?customer={$this->customer->id}");
        expect($done['panel']['rewards'][0])->toMatchArray(['status' => 'redeemed', 'applied_value_cents' => 2500, 'booking_id' => $target->id]);
    });

    it('dit clairement pourquoi une utilisation est refusée', function (): void {
        ($this->program)(['required_visits' => 1, 'expiry_days' => 5]);
        $target = ($this->book)();
        ($this->visit)();
        [$reward] = ($this->rewards)('available');
        $this->travelTo(now()->addDays(6));

        ($this->as)()->post("/clients/fidelite/recompenses/{$reward->id}/utiliser", ['booking_id' => $target->id])->assertSessionHasErrors(['reward' => 'Cette récompense a expiré.']);
        ($this->as)()->post("/clients/fidelite/recompenses/{$reward->id}/utiliser", [])->assertSessionHasErrors('reward');
    });

    it('l\'annulation d\'une utilisation exige un motif', function (): void {
        ($this->program)(['required_visits' => 1]);
        $target = ($this->book)();
        ($this->visit)();
        [$reward] = ($this->rewards)('available');
        ($this->as)()->post("/clients/fidelite/recompenses/{$reward->id}/utiliser", ['booking_id' => $target->id]);

        ($this->as)()->post("/clients/fidelite/recompenses/{$reward->id}/annuler", ['reason' => ''])->assertSessionHasErrors('reason');
        ($this->as)()->post("/clients/fidelite/recompenses/{$reward->id}/annuler", ['reason' => 'Mauvais rendez-vous'])->assertSessionHasNoErrors();

        expect(($this->rewards)('available'))->toHaveCount(1);
    });

    it('ne touche jamais la récompense d\'un autre établissement', function (): void {
        ($this->program)(['required_visits' => 1]);
        ($this->visit)();
        [$reward] = ($this->rewards)('available');
        ['user' => $other, 'org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com');

        $this->signedIn($other, $otherOrg)->post("/clients/fidelite/recompenses/{$reward->id}/utiliser", [])->assertNotFound();
        expect(($this->rewards)('available'))->toHaveCount(1);
    });
});

describe('ajustements, inscription et départ', function (): void {
    it('l\'ajustement exige un motif et laisse une trace', function (): void {
        ($this->program)();

        ($this->as)()->post("/clients/fidelite/membres/{$this->customer->id}/ajuster", ['delta' => 2, 'reason' => ''])->assertSessionHasErrors('reason');
        ($this->as)()->post("/clients/fidelite/membres/{$this->customer->id}/ajuster", ['delta' => 0, 'reason' => 'Test'])->assertSessionHasErrors('delta');
        ($this->as)()->post("/clients/fidelite/membres/{$this->customer->id}/ajuster", ['delta' => -1, 'reason' => 'Trop'])->assertSessionHasErrors('loyalty');
        ($this->as)()->post("/clients/fidelite/membres/{$this->customer->id}/ajuster", ['delta' => 2, 'reason' => 'Ancienne carte'])->assertSessionHasNoErrors();

        expect(($this->ledger)('manual_adjustment'))->toHaveCount(1)->and(($this->ledger)('manual_adjustment')[0]->reason)->toBe('Ancienne carte');
    });

    it('inscrit, fait quitter et réinscrit un client, séparément de tout consentement marketing', function (): void {
        ($this->program)(['auto_enroll' => false]);

        ($this->as)()->post("/clients/fidelite/membres/{$this->customer->id}/inscrire")->assertSessionHasNoErrors();
        ($this->as)()->post("/clients/fidelite/membres/{$this->customer->id}/quitter", ['reason' => 'À sa demande'])->assertSessionHasNoErrors();
        expect(($this->member)()->status)->toBe('opted_out');

        ($this->as)()->post("/clients/fidelite/membres/{$this->customer->id}/reprendre")->assertSessionHasNoErrors();
        expect(($this->member)()->status)->toBe('active')
            ->and(($this->in)(fn () => DB::table('customer_consents')->where('customer_id', $this->customer->id)->count()))->toBe(0);
    });
});

describe('rapport', function (): void {
    it('compte tampons, récompenses, taux d\'utilisation et retour, avec les filtres', function (): void {
        ($this->program)(['required_visits' => 2]);
        $colour = ($this->serviceWith)('Couleur', 6000);
        $target = ($this->book)();
        ($this->visit)();
        ($this->visit)();
        ($this->visit)(null, $colour);
        $other = ($this->customerWith)('+32470111009', 'Hors programme');
        ($this->in)(fn () => app(LoyaltyEngine::class)->optOut($other, $this->owner->id, null));
        [$reward] = ($this->rewards)('available');
        ($this->in)(fn () => app(LoyaltyRedemptions::class)->redeem($reward, $target, $this->owner->id));

        $report = fn (string $query = ''): array => ($this->props)("/clients/fidelite/rapport?from=2026-06-01&to=2026-06-30{$query}")['report'];
        $all = $report();
        $service = $report("&service={$this->service->id}");
        $scoped = $report("&service={$colour->id}");

        expect($all['metrics'])->toMatchArray(['stamps_earned' => 3, 'stamps_reversed' => 0, 'stamps_net' => 3, 'rewards_earned' => 1, 'rewards_redeemed' => 1, 'rewards_outstanding' => 0, 'redemption_rate' => 1.0, 'redemption_rate_base' => 1])
            ->and($all['scoped'])->toBeFalse()->and($all['period'])->toMatchArray(['from' => '2026-06-01', 'to' => '2026-06-30', 'granularity' => 'day'])
            ->and($service['metrics']['stamps_earned'])->toBe(2)->and($service['scoped'])->toBeTrue()->and($scoped['metrics']['stamps_earned'])->toBe(1)->and($scoped['metrics']['rewards_earned'])->toBe(0)
            ->and($all['returning']['enrolled'])->toMatchArray(['customers' => 1, 'repeat' => 1, 'rate' => 1.0]);
    });

    it('sur une période sans activité, ne montre pas de taux ni d\'évolution inventés', function (): void {
        ($this->program)();

        $report = ($this->props)('/clients/fidelite/rapport?from=2026-01-01&to=2026-01-31')['report'];

        expect($report['metrics']['redemption_rate'])->toBeNull()->and($report['series'])->toBe([])->and($report['returning']['enrolled']['rate'])->toBeNull();
    });

    it('filtre par programme, ancien programme archivé compris', function (): void {
        $old = ($this->program)();
        ($this->visit)();
        ($this->in)(fn () => app(LoyaltyPrograms::class)->archive($this->owner->id));
        ($this->program)();
        ($this->visit)();

        $report = fn (string $program): array => ($this->props)("/clients/fidelite/rapport?from=2026-06-01&to=2026-06-30&program={$program}")['report'];

        expect($report($old->id)['metrics']['stamps_earned'])->toBe(1)->and($report($old->id)['programs'])->toHaveCount(2)
            ->and(($this->props)('/clients/fidelite/rapport?from=2026-06-01&to=2026-06-30')['report']['metrics']['stamps_earned'])->toBe(2);
    });
});

describe('API du bot', function (): void {
    beforeEach(function (): void {
        $issued = ($this->in)(fn () => app(ApiClientService::class)->issue($this->org, 'n8n', ApiClient::SCOPES));
        $this->client = $issued['client'];
        $this->secret = $issued['secret'];
        $this->api = function (string $uri, ?array $scopes = null) {
            $timestamp = (string) CarbonImmutable::now()->getTimestamp();
            $canonical = implode("\n", [$timestamp, 'GET', $uri, hash('sha256', '')]);

            return $this->call('GET', $uri, [], [], [], [
                'HTTP_ACCEPT' => 'application/json', 'HTTP_X_NOVA_KEY' => $this->client->key_id, 'HTTP_X_NOVA_TIMESTAMP' => $timestamp,
                'HTTP_X_NOVA_SIGNATURE' => hash_hmac('sha256', $canonical, $this->secret),
            ]);
        };
    });

    it('donne au bot la progression du client dans sa langue, et rien sur un client qui ne participe pas', function (): void {
        ($this->program)(['required_visits' => 3, 'expiry_days' => 30]);
        $this->asOwner(fn () => $this->customer->forceFill(['language' => 'en'])->save());
        ($this->visit)();
        ($this->visit)();

        $data = ($this->api)('/api/v1/loyalty?customer_phone=%2B32470123456')->assertOk()->json('data');

        expect($data)->toMatchArray(['enrolled' => true, 'program' => 'Loyalty', 'stamps' => 2, 'required' => 3, 'remaining' => 1, 'reward' => 'A free haircut', 'earning' => true]);

        expect(($this->api)('/api/v1/loyalty?customer_phone=%2B32470999999')->assertOk()->json('data'))->toBe(['enrolled' => false]);

        ($this->in)(fn () => app(LoyaltyEngine::class)->optOut($this->customer, $this->owner->id));
        expect(($this->api)('/api/v1/loyalty?customer_phone=%2B32470123456')->json('data'))->toBe(['enrolled' => false]);
    });

    it('exige le périmètre loyalty:read et un numéro valide', function (): void {
        $issued = ($this->in)(fn () => app(ApiClientService::class)->issue($this->org, 'limité', ['config:read']));
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $uri = '/api/v1/loyalty?customer_phone=%2B32470123456';
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, 'GET', $uri, hash('sha256', '')]), $issued['secret']);

        $this->call('GET', $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_NOVA_KEY' => $issued['client']->key_id, 'HTTP_X_NOVA_TIMESTAMP' => $timestamp, 'HTTP_X_NOVA_SIGNATURE' => $signature])->assertForbidden();
        ($this->api)('/api/v1/loyalty?customer_phone=pas-un-numero')->assertUnprocessable();
    });
});
