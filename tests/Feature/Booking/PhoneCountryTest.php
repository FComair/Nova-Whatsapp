<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Support\TenantContext;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Un numéro saisi sans « + » est lu dans le pays de l'établissement : le 06 existe en France et aux Pays-Bas, il ne doit jamais
 * changer d'indicatif tout seul.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();

    $this->book = fn (string $phone, string $time = '10:00') => $this->signedIn($this->owner, $this->org)->post('/planning/rendez-vous', [
        'location_id' => $this->location->id,
        'service_id' => $this->service->id,
        'practitioner_id' => $this->practitioner->id,
        'date' => '2026-06-02',
        'time' => $time,
        'phone' => $phone,
        'name' => 'Client',
    ]);
    $this->phones = fn (): array => $this->asOwner(fn () => Customer::query()->pluck('phone_e164')->all());
    $this->inCountry = fn (string $country) => $this->asOwner(fn () => $this->org->forceFill(['country' => $country])->save());
});

it('lit un numéro national dans le pays de l\'établissement', function (string $country, string $input, string $expected): void {
    ($this->inCountry)($country);

    ($this->book)($input)->assertSessionHasNoErrors();

    expect(($this->phones)())->toContain($expected);
})->with([
    'Pays-Bas' => ['NL', '06 12345678', '+31612345678'],
    'France' => ['FR', '06 12 34 56 78', '+33612345678'],
    'Belgique' => ['BE', '0470 12 34 56', '+32470123456'],
]);

it('garde l\'indicatif d\'un numéro international, quel que soit le pays de l\'établissement', function (): void {
    ($this->inCountry)('NL');

    ($this->book)('+32 470 12 34 56')->assertSessionHasNoErrors();

    expect(($this->phones)())->toContain('+32470123456');
});

it('refuse un numéro national qui n\'existe pas dans le pays de l\'établissement, au lieu de choisir un autre pays', function (): void {
    ($this->inCountry)('BE');

    ($this->book)('06 12345678')->assertSessionHasErrors('phone');

    expect(($this->phones)())->not->toContain('+33612345678')->not->toContain('+31612345678');
});
