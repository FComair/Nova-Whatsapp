<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Semaine type envoyée par le formulaire : par défaut ouvert du lundi au vendredi 09:00-12:00 et 13:00-18:00.
 *
 * @param  list<int>  $days
 * @param  list<array{start: string, end: string}>  $ranges
 * @return list<array{weekday: int, ranges: list<array{start: string, end: string}>}>
 */
function hoursWeek(array $days = [1, 2, 3, 4, 5], array $ranges = [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '18:00']]): array
{
    return array_map(
        fn (int $weekday): array => ['weekday' => $weekday, 'ranges' => in_array($weekday, $days, true) ? $ranges : []],
        range(1, 7),
    );
}

/**
 * Étape 3 : horaires d'ouverture (plusieurs plages par jour, un jour sans plage est fermé).
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(onboarded: false);
    $this->main = $this->asOwner(fn () => Location::query()->where('org_id', $this->org->id)->firstOrFail());
    $this->as = fn () => $this->signedIn($this->owner, $this->org);

    $this->week = fn (...$arguments) => hoursWeek(...$arguments);
    $this->put = fn (?Location $site = null, ?array $hours = null) => ($this->as)()->put('/configuration/assistant/horaires/'.($site ?? $this->main)->id, ['hours' => $hours ?? ($this->week)()]);
});

describe('affichage', function (): void {
    it('affiche une semaine fermée pour un site sans horaires', function (): void {
        ($this->as)()->get('/configuration/assistant/horaires')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Hours')
            ->where('selectedId', $this->main->id)
            ->where('locations.0.configured', false)
            ->where('week.1', [])
            ->where('week.7', []));
    });

    it('affiche les horaires enregistrés, triés', function (): void {
        ($this->put)();

        ($this->as)()->get('/configuration/assistant/horaires')->assertInertia(fn (Assert $page) => $page
            ->where('week.1', [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '18:00']])
            ->where('week.6', [])
            ->where('locations.0.configured', true));
    });

    it('sélectionne le site demandé, et ignore un site d\'un autre client', function (): void {
        $second = $this->asOwner(fn () => Location::factory()->create(['org_id' => $this->org->id, 'name' => 'Deuxième']));
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');
        $foreign = $this->asOwner(fn () => Location::query()->where('org_id', $other->id)->firstOrFail());

        ($this->as)()->get("/configuration/assistant/horaires?site={$second->id}")->assertInertia(fn (Assert $page) => $page->where('selectedId', $second->id));
        ($this->as)()->get("/configuration/assistant/horaires?site={$foreign->id}")->assertInertia(fn (Assert $page) => $page->where('selectedId', $this->main->id)->has('locations', 2));
    });
});

describe('enregistrement', function (): void {
    it('enregistre les plages, marque l\'étape faite et passe à la suite', function (): void {
        ($this->put)()->assertRedirect('/configuration/assistant/services');

        $rows = $this->asOwner(fn () => DB::table('business_hours')->where('location_id', $this->main->id)->orderBy('weekday')->orderBy('starts_at')->get());
        expect($rows)->toHaveCount(10)
            ->and($rows->pluck('weekday')->unique()->values()->all())->toBe([1, 2, 3, 4, 5])
            ->and(substr($rows[0]->starts_at, 0, 5))->toBe('09:00')
            ->and(substr($rows[1]->ends_at, 0, 5))->toBe('18:00');

        expect($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('hours')))->toBeTrue();
    });

    it('remplace les horaires précédents', function (): void {
        ($this->put)();
        ($this->put)(null, ($this->week)([6], [['start' => '10:00', 'end' => '14:00']]))->assertRedirect();

        $rows = $this->asOwner(fn () => DB::table('business_hours')->where('location_id', $this->main->id)->get());
        expect($rows)->toHaveCount(1)->and($rows[0]->weekday)->toBe(6);
    });

    it('trie les plages envoyées dans le désordre', function (): void {
        ($this->put)(null, ($this->week)([1], [['start' => '14:00', 'end' => '18:00'], ['start' => '08:00', 'end' => '12:00']]))->assertSessionHasNoErrors();

        $starts = $this->asOwner(fn () => DB::table('business_hours')->where('weekday', 1)->orderBy('starts_at')->pluck('starts_at')->map(fn ($t) => substr($t, 0, 5))->all());
        expect($starts)->toBe(['08:00', '14:00']);
    });

    it('trace le changement avec l\'état avant et après', function (): void {
        ($this->put)();

        $log = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'business_hours.updated')->first());
        $diff = json_decode($log->diff, true);

        expect($log->entity_id)->toBe($this->main->id)
            ->and($diff['before']['1'])->toBe([])
            ->and($diff['after']['1'])->toHaveCount(2);
    });

    it('n\'accepte pas de plage qui traverse minuit ni de fin avant le début', function (): void {
        ($this->put)(null, ($this->week)([1], [['start' => '18:00', 'end' => '09:00']]))->assertSessionHasErrors('hours.0.ranges.0.end');
        ($this->put)(null, ($this->week)([1], [['start' => '09:00', 'end' => '09:00']]))->assertSessionHasErrors('hours.0.ranges.0.end');
    });

    it('refuse les données invalides', function (array $hours, string $field): void {
        ($this->put)(null, $hours)->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => DB::table('business_hours')->count()))->toBe(0)
            ->and($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('hours')))->toBeFalse();
    })->with([
        'plages qui se chevauchent' => [hoursWeek([1], [['start' => '09:00', 'end' => '13:00'], ['start' => '12:00', 'end' => '18:00']]), 'hours.0.ranges.1.start'],
        'plus de 3 plages dans la journée' => [hoursWeek([1], [
            ['start' => '08:00', 'end' => '09:00'], ['start' => '10:00', 'end' => '11:00'], ['start' => '12:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '15:00'],
        ]), 'hours.0.ranges'],
        'aucun jour ouvert' => [hoursWeek([], []), 'hours'],
        'format d\'heure invalide' => [hoursWeek([1], [['start' => '9h', 'end' => '12:00']]), 'hours.0.ranges.0.start'],
        'heure inexistante' => [hoursWeek([1], [['start' => '25:00', 'end' => '26:00']]), 'hours.0.ranges.0.start'],
        'jour en double' => [[...hoursWeek([1]), ['weekday' => 1, 'ranges' => []]], 'hours'],
        'semaine incomplète' => [array_slice(hoursWeek([1]), 0, 5), 'hours'],
        'jour hors semaine' => [array_replace(hoursWeek([1]), [6 => ['weekday' => 8, 'ranges' => []]]), 'hours.6.weekday'],
    ]);

    it('accepte des plages consécutives qui se touchent', function (): void {
        ($this->put)(null, ($this->week)([1], [['start' => '09:00', 'end' => '12:00'], ['start' => '12:00', 'end' => '18:00']]))->assertSessionHasNoErrors();
    });
});

describe('plusieurs sites', function (): void {
    beforeEach(function (): void {
        $this->second = $this->asOwner(fn () => Location::factory()->create(['org_id' => $this->org->id, 'name' => 'Deuxième']));
    });

    it('ne termine l\'étape que lorsque chaque site a ses horaires', function (): void {
        ($this->put)()->assertRedirect("/configuration/assistant/horaires?site={$this->second->id}");
        expect($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('hours')))->toBeFalse();

        ($this->put)($this->second)->assertRedirect('/configuration/assistant/services');
        expect($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('hours')))->toBeTrue();
    });

    it('indique quel site n\'a pas encore d\'horaires', function (): void {
        ($this->put)();

        ($this->as)()->get('/configuration/assistant/horaires')->assertInertia(fn (Assert $page) => $page
            ->where('locations.0.configured', true)
            ->where('locations.1.configured', false));
    });

    it('garde les horaires de chaque site séparés', function (): void {
        ($this->put)($this->main, ($this->week)([1], [['start' => '09:00', 'end' => '17:00']]));
        ($this->put)($this->second, ($this->week)([2], [['start' => '10:00', 'end' => '16:00']]));

        expect($this->asOwner(fn () => DB::table('business_hours')->where('location_id', $this->main->id)->pluck('weekday')->all()))->toBe([1])
            ->and($this->asOwner(fn () => DB::table('business_hours')->where('location_id', $this->second->id)->pluck('weekday')->all()))->toBe([2]);
    });
});

describe('garanties de la base de données et isolation', function (): void {
    it('refuse en base deux plages qui se chevauchent, même sans passer par la validation', function (): void {
        $this->asOwner(function (): void {
            $row = ['org_id' => $this->org->id, 'location_id' => $this->main->id, 'weekday' => 1];
            BusinessHour::query()->create([...$row, 'starts_at' => '09:00', 'ends_at' => '12:00']);
            BusinessHour::query()->create([...$row, 'starts_at' => '11:00', 'ends_at' => '14:00']);
        });
    })->throws(QueryException::class, 'business_hours_no_overlap');

    it('refuse en base une plage dont la fin précède le début', function (): void {
        $this->asOwner(fn () => BusinessHour::query()->create([
            'org_id' => $this->org->id, 'location_id' => $this->main->id, 'weekday' => 1, 'starts_at' => '18:00', 'ends_at' => '09:00',
        ]));
    })->throws(QueryException::class, 'business_hours_range_check');

    it('accepte les mêmes horaires sur deux jours ou deux sites différents', function (): void {
        $second = $this->asOwner(fn () => Location::factory()->create(['org_id' => $this->org->id]));

        $this->asOwner(function () use ($second): void {
            foreach ([[$this->main->id, 1], [$this->main->id, 2], [$second->id, 1]] as [$location, $weekday]) {
                BusinessHour::query()->create(['org_id' => $this->org->id, 'location_id' => $location, 'weekday' => $weekday, 'starts_at' => '09:00', 'ends_at' => '12:00']);
            }
        });

        expect($this->asOwner(fn () => BusinessHour::query()->count()))->toBe(3);
    });

    it('cache les horaires d\'un autre client (RLS)', function (): void {
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');
        $foreign = $this->asOwner(fn () => Location::query()->where('org_id', $other->id)->firstOrFail());
        $this->asOwner(fn () => BusinessHour::query()->create(['org_id' => $other->id, 'location_id' => $foreign->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '20:00']));
        ($this->put)();

        app(TenantContext::class)->set($this->org->id);
        expect(BusinessHour::query()->pluck('org_id')->unique()->all())->toBe([$this->org->id]);
    });

    it('répond 404 pour enregistrer les horaires d\'un site d\'un autre client', function (): void {
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');
        $foreign = $this->asOwner(fn () => Location::query()->where('org_id', $other->id)->firstOrFail());

        ($this->put)($foreign)->assertNotFound();

        expect($this->asOwner(fn () => BusinessHour::query()->count()))->toBe(0);
    });

    it('supprime les horaires avec le site (cascade)', function (): void {
        ($this->put)();

        $this->asOwner(fn () => DB::table('locations')->where('id', $this->main->id)->delete());

        expect($this->asOwner(fn () => BusinessHour::query()->count()))->toBe(0);
    });
});
