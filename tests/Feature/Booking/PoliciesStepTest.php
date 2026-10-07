<?php

declare(strict_types=1);

use App\Modules\Booking\Models\BookingPolicy;
use App\Modules\Booking\Services\PolicyService;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Étape 6 : politiques de réservation (délais, annulation, no-show, surbooking, rappel).
 */
function validPolicy(array $override = []): array
{
    return array_merge([
        'min_notice_hours' => 4, 'max_horizon_days' => 90, 'cancellation_window_hours' => 48, 'no_show_fee' => '15,50',
        'allow_overbooking' => false, 'hold_minutes' => 7, 'reminder_hours_before' => 24,
    ], $override);
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(onboarded: false);
    $this->as = fn () => $this->signedIn($this->owner, $this->org);
    $this->save = fn (array $override = []) => ($this->as)()->post('/configuration/assistant/politiques', validPolicy($override));
    $this->policy = fn () => $this->asOwner(fn () => BookingPolicy::query()->find($this->org->id));
});

describe('page et valeurs par défaut', function (): void {
    it('propose des valeurs par défaut adaptées à la plupart des commerces', function (): void {
        ($this->as)()->get('/configuration/assistant/politiques')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Policies')
            ->where('policy', [
                'min_notice_hours' => 2, 'max_horizon_days' => 60, 'cancellation_window_hours' => 24, 'no_show_fee_cents' => 0,
                'allow_overbooking' => false, 'hold_minutes' => 10, 'reminder_hours_before' => 24,
            ]));
    });

    it('crée la ligne de politiques à la première visite, une seule fois', function (): void {
        ($this->as)()->get('/configuration/assistant/politiques');
        ($this->as)()->get('/configuration/assistant/politiques');

        expect($this->asOwner(fn () => BookingPolicy::query()->where('org_id', $this->org->id)->count()))->toBe(1);
    });

    it('réaffiche les valeurs enregistrées', function (): void {
        ($this->save)(['no_show_fee' => '20']);

        ($this->as)()->get('/configuration/assistant/politiques')->assertInertia(fn (Assert $page) => $page
            ->where('policy.min_notice_hours', 4)
            ->where('policy.no_show_fee_cents', 2000)
            ->where('policy.hold_minutes', 7));
    });

    it('utilise les valeurs par défaut de la classe, cohérentes avec la base', function (): void {
        $tenant = app(TenantContext::class);
        $policy = $tenant->run($this->org->id, fn () => app(PolicyService::class)->for($this->org)->only(array_keys(BookingPolicy::DEFAULTS)));

        expect($policy)->toEqual(BookingPolicy::DEFAULTS);
    });
});

describe('enregistrement', function (): void {
    it('enregistre les politiques, marque l\'étape faite et ouvre la suivante', function (): void {
        ($this->save)()->assertSessionHasNoErrors()->assertRedirect('/configuration/assistant/whatsapp');

        $policy = ($this->policy)();
        expect($policy->min_notice_hours)->toBe(4)
            ->and($policy->max_horizon_days)->toBe(90)
            ->and($policy->cancellation_window_hours)->toBe(48)
            ->and($policy->no_show_fee_cents)->toBe(1550)
            ->and($policy->allow_overbooking)->toBeFalse()
            ->and($policy->hold_minutes)->toBe(7)
            ->and($policy->reminder_hours_before)->toBe(24);

        expect($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('policies')))->toBeTrue();
    });

    it('accepte les valeurs limites', function (array $override): void {
        ($this->save)($override)->assertSessionHasNoErrors();
    })->with([
        'aucun délai, aucune annulation, aucun rappel, aucun frais' => [['min_notice_hours' => 0, 'cancellation_window_hours' => 0, 'reminder_hours_before' => 0, 'no_show_fee' => '0']],
        'valeurs maximales' => [['min_notice_hours' => 168, 'max_horizon_days' => 365, 'cancellation_window_hours' => 168, 'reminder_hours_before' => 72, 'no_show_fee' => '500', 'hold_minutes' => 10]],
        'blocage minimal' => [['hold_minutes' => 5]],
        'horizon minimal' => [['max_horizon_days' => 1, 'min_notice_hours' => 0]],
    ]);

    it('active le surbooking uniquement sur demande', function (): void {
        ($this->save)(['allow_overbooking' => true]);

        expect(($this->policy)()->allow_overbooking)->toBeTrue();
    });

    it('accepte un montant avec un point ou une virgule', function (string $fee, int $cents): void {
        ($this->save)(['no_show_fee' => $fee])->assertSessionHasNoErrors();

        expect(($this->policy)()->no_show_fee_cents)->toBe($cents);
    })->with([['15.5', 1550], ['15,50', 1550], ['0', 0], ['500', 50000]]);

    it('trace les changements avec l\'ancienne et la nouvelle valeur', function (): void {
        ($this->save)();

        $log = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'booking_policies.updated')->where('org_id', $this->org->id)->first());
        $diff = json_decode($log->diff, true);

        expect($diff['min_notice_hours'])->toBe([2, 4])
            ->and($diff['max_horizon_days'])->toBe([60, 90])
            ->and($diff['no_show_fee_cents'])->toBe([0, 1550])
            ->and($diff)->not->toHaveKey('allow_overbooking');
    });

    it('n\'écrit pas d\'audit quand rien ne change', function (): void {
        ($this->save)(['min_notice_hours' => 2, 'max_horizon_days' => 60, 'cancellation_window_hours' => 24, 'no_show_fee' => '0', 'hold_minutes' => 10, 'reminder_hours_before' => 24])
            ->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'booking_policies.updated')->count()))->toBe(0);
    });

    it('refuse les données invalides et ne modifie rien', function (array $override, string $field): void {
        ($this->save)($override)->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => BookingPolicy::query()->find($this->org->id)))->toBeNull()
            ->and($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('policies')))->toBeFalse();
    })->with([
        'délai négatif' => [['min_notice_hours' => -1], 'min_notice_hours'],
        'délai trop long' => [['min_notice_hours' => 169], 'min_notice_hours'],
        'délai non numérique' => [['min_notice_hours' => 'abc'], 'min_notice_hours'],
        'horizon nul' => [['max_horizon_days' => 0], 'max_horizon_days'],
        'horizon trop long' => [['max_horizon_days' => 366], 'max_horizon_days'],
        'annulation trop longue' => [['cancellation_window_hours' => 169], 'cancellation_window_hours'],
        'frais invalides' => [['no_show_fee' => 'abc'], 'no_show_fee'],
        'frais négatifs' => [['no_show_fee' => '-5'], 'no_show_fee'],
        'frais trop élevés' => [['no_show_fee' => '500,01'], 'no_show_fee'],
        'frais vides' => [['no_show_fee' => ''], 'no_show_fee'],
        'blocage trop court' => [['hold_minutes' => 4], 'hold_minutes'],
        'blocage trop long' => [['hold_minutes' => 11], 'hold_minutes'],
        'rappel trop long' => [['reminder_hours_before' => 73], 'reminder_hours_before'],
        'surbooking invalide' => [['allow_overbooking' => 'peut-être'], 'allow_overbooking'],
        'délai supérieur à l\'horizon' => [['max_horizon_days' => 2, 'min_notice_hours' => 48], 'min_notice_hours'],
    ]);

    it('affiche les messages d\'erreur dans la langue de l\'interface', function (string $locale, string $message): void {
        $this->asOwner(fn () => $this->owner->forceFill(['locale' => $locale])->save());

        ($this->save)(['max_horizon_days' => 1, 'min_notice_hours' => 24])->assertSessionHasErrors(['min_notice_hours' => $message]);
    })->with([
        ['fr', 'Le délai minimum doit être plus court que la période de réservation.'],
        ['nl', 'De minimale termijn moet korter zijn dan de reservatieperiode.'],
        ['en', 'The minimum notice must be shorter than the booking period.'],
    ]);
});

describe('rôles et isolation', function (): void {
    it('est réservé au propriétaire', function (string $role): void {
        ['user' => $user, 'org' => $org] = $this->activatedAccount(email: "{$role}@example.com", role: $role);
        $this->signedIn($user, $org);

        $this->get('/configuration/assistant/politiques')->assertForbidden();
        $this->post('/configuration/assistant/politiques', validPolicy())->assertForbidden();

        expect($this->asOwner(fn () => BookingPolicy::query()->where('org_id', $org->id)->count()))->toBe(0);
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('refuse toute écriture quand le compte est suspendu', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended'])->save());

        ($this->save)()->assertRedirect('/compte');

        expect($this->asOwner(fn () => BookingPolicy::query()->count()))->toBe(0);
    });

    it('ne touche pas aux politiques d\'un autre client', function (): void {
        ['user' => $otherOwner, 'org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com', onboarded: false);
        $this->signedIn($otherOwner, $otherOrg)->post('/configuration/assistant/politiques', validPolicy(['min_notice_hours' => 12]));

        ($this->save)(['min_notice_hours' => 1]);

        expect($this->asOwner(fn () => BookingPolicy::query()->find($otherOrg->id)->min_notice_hours))->toBe(12)
            ->and(($this->policy)()->min_notice_hours)->toBe(1);
    });

    it('cache les politiques d\'un autre client (RLS)', function (): void {
        ($this->save)();
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');

        app(TenantContext::class)->set($other->id);

        expect(BookingPolicy::query()->count())->toBe(0);
    });
});

describe('garanties de la base de données', function (): void {
    it('refuse en base des valeurs hors limites', function (array $attributes): void {
        $this->asOwner(fn () => DB::table('booking_policies')->insert(array_merge(['org_id' => $this->org->id, 'created_at' => now(), 'updated_at' => now()], $attributes)));
    })->with([
        'blocage' => [['hold_minutes' => 20]],
        'horizon' => [['max_horizon_days' => 0]],
        'rappel' => [['reminder_hours_before' => 100]],
        'frais' => [['no_show_fee_cents' => 60000]],
        'annulation' => [['cancellation_window_hours' => 500]],
        'délai supérieur à l\'horizon' => [['max_horizon_days' => 1, 'min_notice_hours' => 30]],
    ])->throws(QueryException::class);

    it('n\'autorise qu\'une ligne de politiques par organisation', function (): void {
        $this->asOwner(function (): void {
            DB::table('booking_policies')->insert(['org_id' => $this->org->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('booking_policies')->insert(['org_id' => $this->org->id, 'created_at' => now(), 'updated_at' => now()]);
        });
    })->throws(QueryException::class);

    it('supprime les politiques avec l\'organisation (cascade)', function (): void {
        ($this->save)();

        $this->asOwner(fn () => DB::table('organizations')->where('id', $this->org->id)->delete());

        expect($this->asOwner(fn () => DB::table('booking_policies')->count()))->toBe(0);
    });
});
