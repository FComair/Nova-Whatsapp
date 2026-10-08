<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerConsent;
use App\Modules\Customers\Services\ConsentRecorder;
use App\Modules\Privacy\Services\CustomerErasure;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Socle du centre clients : numéro de référence, index de recherche, statut cohérent, consentements en ajout seul.
 */
beforeEach(function (): void {
    ['org' => $this->org] = $this->activatedAccount();
    app(TenantContext::class)->clear();
    $this->add = fn (string $phone, ?string $name = null, array $extra = []): Customer => $this->asOwner(fn () => Customer::query()->create(['org_id' => $this->org->id, 'phone_e164' => $phone, 'name' => $name] + $extra)->refresh());
});

it('attribue un numéro de référence par établissement à partir de 1001', function (): void {
    $a = ($this->add)('+32470000001', 'Aïcha Martin');
    $b = ($this->add)('+32470000002', 'Bob');

    expect($a->reference_number)->toBe(1001)->and($b->reference_number)->toBe(1002)->and($b->reference())->toBe('C-1002');
});

it('construit un texte de recherche sans accents ni majuscules', function (): void {
    $c = ($this->add)('+32470000003', 'Aïcha Martin', ['email' => 'Aicha@Example.be', 'tags' => ['VIP']]);

    $search = (string) $this->asOwner(fn () => DB::selectOne('select search_text from customers where id = ?', [$c->id])?->search_text);
    expect($search)->toContain('aicha martin')->and($search)->toContain('aicha@example.be')->and($search)->toContain('vip')->and($search)->toContain('c-'.$c->reference_number);
});

it('garde le statut et is_blocked cohérents', function (): void {
    $c = ($this->add)('+32470000004', 'Zoé');
    $this->asOwner(fn () => $c->setStatus('blocked'));
    expect($c->fresh()->is_blocked)->toBeTrue();

    $this->asOwner(fn () => $c->setStatus('archived'));
    expect($c->fresh()->is_blocked)->toBeFalse();

    expect(fn () => $this->asOwner(fn () => DB::statement('update customers set is_blocked = true where id = ?', [$c->id])))->toThrow(QueryException::class);
});

it('enregistre les consentements en ajout seul', function (): void {
    $c = ($this->add)('+32470000005', 'Léa');

    $this->asOwner(function () use ($c): void {
        $recorder = app(ConsentRecorder::class);
        $recorder->recordStart($c);
        expect($recorder->whatsappStatus($c))->toBe('unknown');

        $recorder->record($c, 'marketing', 'granted', 'form', null, 'papier');
        expect($recorder->whatsappStatus($c))->toBe('opted_in')->and($recorder->canSendMarketing($c))->toBeTrue();

        $recorder->recordStop($c);
        expect($recorder->whatsappStatus($c))->toBe('opted_out')->and($recorder->canSendMarketing($c))->toBeFalse();
        expect($recorder->history($c))->toHaveCount(4);
    });

    expect(fn () => $this->asOwner(fn () => CustomerConsent::query()->where('customer_id', $c->id)->delete()))->toThrow(QueryException::class);
    expect(fn () => $this->asOwner(fn () => DB::statement("update customer_consents set status = 'granted' where customer_id = ?", [$c->id])))->toThrow(QueryException::class);
});

it('efface aussi les nouvelles données personnelles', function (): void {
    $c = ($this->add)('+32470000006', 'Nina Roche', ['first_name' => 'Nina', 'last_name' => 'Roche', 'date_of_birth' => '1990-05-04', 'address' => ['city' => 'Liège'], 'custom' => ['x' => 'y']]);

    $this->asOwner(fn () => app(CustomerErasure::class)->erase($this->org, $c, null));

    $fresh = $this->asOwner(fn () => Customer::withTrashed()->find($c->id));
    expect($fresh->first_name)->toBeNull()->and($fresh->last_name)->toBeNull()->and($fresh->date_of_birth)->toBeNull()->and($fresh->address)->toBeNull()->and($fresh->custom)->toBe([])->and($fresh->status)->toBe('archived');
});
