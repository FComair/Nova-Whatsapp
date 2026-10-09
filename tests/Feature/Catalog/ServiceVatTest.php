<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Catalog\Models\Service;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * TVA libre : le client peut saisir son propre taux (0 à 100 %, deux décimales au plus) en plus des taux de la liste.
 */
function vatService(array $override = []): array
{
    return array_merge([
        'name' => 'Soin', 'category' => '', 'description' => '', 'duration_minutes' => 30, 'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0, 'price' => '30', 'vat_rate' => 21, 'capacity' => 1, 'bookable_online' => true, 'notes_required' => false,
    ], $override);
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount(['sector' => 'hairdressing', 'locale' => 'fr']);
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();

    $this->as = fn () => $this->signedIn($this->owner, $this->org);
    $this->vat = fn (string $name = 'Soin'): mixed => $this->asOwner(fn () => Service::query()->where('name', $name)->firstOrFail()->vat_rate);
});

it('enregistre un taux personnalisé, avec virgule, point ou signe %', function (mixed $input, int|float $expected): void {
    ($this->as)()->post('/configuration/services', vatService(['vat_rate' => $input]))->assertSessionHasNoErrors();

    expect(($this->vat)())->toBe($expected);
})->with([
    'décimal avec virgule' => ['21,5', 21.5],
    'décimal avec point' => ['21.5', 21.5],
    'deux décimales' => ['6.25', 6.25],
    'avec le signe %' => [' 12 % ', 12],
    'un taux de la liste' => [6, 6],
    'zéro' => ['0', 0],
    'cent' => ['100', 100],
    'taux non standard entier' => ['7', 7],
]);

it('refuse un taux invalide avec un message clair', function (mixed $input): void {
    ($this->as)()->post('/configuration/services', vatService(['vat_rate' => $input]))
        ->assertSessionHasErrors(['vat_rate' => 'Saisissez un taux de TVA valide entre 0 et 100 (deux décimales au plus).']);

    expect($this->asOwner(fn () => Service::query()->where('name', 'Soin')->exists()))->toBeFalse();
})->with(['négatif' => '-1', 'au-dessus de 100' => '100.01', 'texte' => 'abc', 'trois décimales' => '21.555', 'vide' => '', 'notation scientifique' => '1e2']);

it('remplit de nouveau le champ avec le taux enregistré à la modification', function (): void {
    ($this->as)()->post('/configuration/services', vatService(['vat_rate' => '21,5']));
    $service = $this->asOwner(fn () => Service::query()->where('name', 'Soin')->firstOrFail());

    ($this->as)()->get('/configuration/assistant/services')->assertInertia(fn (Assert $page) => $page->where('services', fn ($services) => collect($services)->firstWhere('name', 'Soin')['vat_rate'] === 21.5)->where('vatRates', [0, 6, 12, 21]));

    ($this->as)()->put("/configuration/services/{$service->id}", vatService(['vat_rate' => '9']))->assertSessionHasNoErrors();
    expect(($this->vat)())->toBe(9);
});

it('refuse en base un taux hors de 0 à 100 %', function (): void {
    expect(fn () => $this->asOwner(fn () => Service::query()->create(['org_id' => $this->org->id, 'name' => 'Test', 'duration_minutes' => 30, 'price_cents' => 1000, 'vat_rate' => 100.5])))->toThrow(QueryException::class, 'services_vat_check');
});

it('garde un taux entier comme entier et un taux décimal comme décimal', function (): void {
    $this->asOwner(function (): void {
        $service = Service::query()->create(['org_id' => $this->org->id, 'name' => 'Rond', 'duration_minutes' => 30, 'price_cents' => 1000, 'vat_rate' => 21]);
        $other = Service::query()->create(['org_id' => $this->org->id, 'name' => 'Décimal', 'duration_minutes' => 30, 'price_cents' => 1000, 'vat_rate' => 12.5]);

        expect($service->fresh()->vat_rate)->toBeInt()->toBe(21)->and($other->fresh()->vat_rate)->toBeFloat()->toBe(12.5);
        expect(DB::table('services')->where('id', $other->id)->value('vat_rate'))->toBe('12.50');
    });
});

it('recopie le taux dans la réservation, qui le garde même si le service change ensuite', function (): void {
    $this->asOwner(fn () => $this->service->forceFill(['vat_rate' => 12.5])->save());

    $booking = app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00')));
    expect($this->asOwner(fn () => Booking::query()->findOrFail($booking->id)->vat_rate))->toBe(12.5);

    $this->asOwner(fn () => $this->service->forceFill(['vat_rate' => 6])->save());
    expect($this->asOwner(fn () => Booking::query()->findOrFail($booking->id)->vat_rate))->toBe(12.5);
});
