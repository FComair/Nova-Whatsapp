<?php

declare(strict_types=1);

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Support\PlanLimits;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Offres Basic (29,99 €) et Business (49,99 €) et limites incluses (docs/04, décision n° 20).
 */
it('propose les deux offres validées avec leurs prix', function (): void {
    $plans = Plan::query()->orderBy('position')->get()->keyBy('code');

    expect($plans->keys()->all())->toBe(['basic', 'business'])
        ->and($plans['basic']->name)->toBe('Basic')
        ->and($plans['basic']->price_monthly_cents)->toBe(2999)
        ->and($plans['business']->name)->toBe('Business')
        ->and($plans['business']->price_monthly_cents)->toBe(4999)
        ->and($plans['basic']->currency ?? 'EUR')->toBe('EUR');
});

it('fixe les limites de praticiens du plan de tarification', function (): void {
    $plans = Plan::query()->get()->keyBy('code');

    expect($plans['basic']->limits['practitioners'])->toBe(1)
        ->and($plans['business']->limits['practitioners'])->toBe(5)
        ->and($plans['basic']->limits['locations'])->toBe(1)
        ->and($plans['business']->limits['locations'])->toBe(1);
});

describe('offre applicable à une organisation', function (): void {
    beforeEach(function (): void {
        $this->limits = app(PlanLimits::class);
        $this->org = fn (array $state) => $this->asOwner(fn () => Organization::factory()->create($state));
    });

    it('utilise l\'offre choisie à l\'inscription', function (string $signup, string $expected): void {
        expect($this->limits->planFor(($this->org)(['signup_plan' => $signup]))->code)->toBe($expected);
    })->with([['basic', 'basic'], ['business', 'business']]);

    it('donne l\'offre Business à un essai sans choix (hypothèse à valider)', function (?string $signup): void {
        expect($this->limits->planFor(($this->org)(['signup_plan' => $signup]))->code)->toBe('business');
    })->with(['trial', null, 'inconnue']);

    it('privilégie l\'abonnement (plan_id) sur le choix d\'inscription', function (): void {
        $basic = Plan::query()->where('code', 'basic')->firstOrFail();
        $org = ($this->org)(['signup_plan' => 'business', 'plan_id' => $basic->id]);

        expect($this->limits->planFor($org)->code)->toBe('basic');
    });

    it('renvoie la limite demandée, ou null si elle est illimitée ou inconnue', function (): void {
        $org = ($this->org)(['signup_plan' => 'basic']);

        expect($this->limits->limit($org, 'practitioners'))->toBe(1)
            ->and($this->limits->limit($org, 'conversations'))->toBe(150)
            ->and($this->limits->limit($org, 'appointments'))->toBe(90);

        // Offre de test sans limite de praticiens (créée avec le rôle propriétaire, retirée ensuite).
        $unlimited = $this->asOwner(fn () => Plan::query()->create(['code' => 'test', 'name' => 'Test', 'price_monthly_cents' => 1, 'limits' => ['practitioners' => null]]));

        try {
            expect($this->limits->limit(($this->org)(['plan_id' => $unlimited->id]), 'practitioners'))->toBeNull();
        } finally {
            $this->asOwner(fn () => DB::table('plans')->where('id', $unlimited->id)->delete());
        }
    });
});

describe('lecture seule pour l\'application cliente', function (): void {
    it('interdit au rôle client de créer, modifier ou supprimer une offre', function (): void {
        expect(fn () => DB::table('plans')->insert(['id' => (string) Str::uuid7(), 'code' => 'pirate', 'name' => 'Pirate', 'price_monthly_cents' => 1, 'limits' => '{}', 'created_at' => now(), 'updated_at' => now()]))
            ->toThrow(QueryException::class, 'permission denied')
            ->and(fn () => DB::table('plans')->update(['price_monthly_cents' => 1]))->toThrow(QueryException::class, 'permission denied')
            ->and(fn () => DB::table('plans')->delete())->toThrow(QueryException::class, 'permission denied');
    });

    it('permet au client de lire les offres', function (): void {
        expect(DB::table('plans')->count())->toBe(2);
    });

    it('permet au rôle Super Admin de lire les offres', function (): void {
        expect(DB::connection('pgsql_platform')->table('plans')->count())->toBe(2);
    });

    it('conserve les offres quand les tables métier sont vidées', function (): void {
        $this->activatedAccount();

        expect($this->asOwner(fn () => Plan::query()->count()))->toBe(2);
    });

    it('détache l\'offre d\'une organisation quand elle est retirée, sans supprimer l\'organisation', function (): void {
        $basic = Plan::query()->where('code', 'basic')->firstOrFail();
        $org = $this->asOwner(fn () => Organization::factory()->create(['plan_id' => $basic->id]));

        try {
            // Suppression par le propriétaire du schéma (ON DELETE SET NULL sur la clé étrangère).
            $this->asOwner(fn () => DB::table('plans')->where('id', $basic->id)->delete());

            expect($this->asOwner(fn () => Organization::query()->find($org->id)->plan_id))->toBeNull();
        } finally {
            // Remet l'offre supprimée pour ne pas polluer les autres tests.
            $this->asOwner(fn () => DB::table('plans')->insert([
                'id' => $basic->id, 'code' => 'basic', 'name' => 'Basic', 'price_monthly_cents' => 2999, 'position' => 1,
                'limits' => json_encode($basic->limits), 'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    });
});
