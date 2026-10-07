<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Privacy\Support\ErasureSteps;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use App\Modules\Whatsapp\Services\MessageSender;
use App\Modules\Whatsapp\Services\WhatsappUsage;
use App\Modules\Whatsapp\Support\SendRefused;
use App\Modules\Whatsapp\Support\TransportFailed;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingScenarios;

uses(BookingScenarios::class);

/**
 * Mesure de la consommation WhatsApp (base de la refacturation, décision du fondateur) : compteurs mensuels par catégorie et pays.
 * « Maintenant » = lundi 1er juin 2026.
 */
beforeEach(function (): void {
    $this->bookingWorld();
    $this->asOwner(fn () => WhatsappAccount::query()->create(['org_id' => $this->org->id, 'phone_number_id' => 'PN-1', 'access_token_enc' => 'token-test']));
    $this->transport = app(WhatsappTransport::class);
    $this->sender = app(MessageSender::class);

    $this->template = fn (?Customer $customer = null) => app(TenantContext::class)->run($this->org->id, fn () => $this->sender->sendTemplate($this->org, $customer ?? $this->customer, 'appointment_reminder', 'fr', ['a', 'b', 'c', 'd']));
    $this->usage = fn () => DB::connection('pgsql_owner')->table('whatsapp_usage')->where('org_id', $this->org->id)->orderBy('category')->orderBy('country')->get(['month', 'category', 'country', 'messages'])
        ->map(fn ($row): array => [substr((string) $row->month, 0, 10), $row->category, $row->country, (int) $row->messages])->all();
    $this->foreign = fn (string $phone) => $this->asOwner(fn () => Customer::query()->create(['org_id' => $this->org->id, 'phone_e164' => $phone, 'name' => 'Client étranger']));
});

it('compte un message modèle comme « utility », au pays du destinataire', function (): void {
    ($this->template)();

    expect(($this->usage)())->toBe([['2026-06-01', 'utility', 'BE', 1]]);
});

it('additionne les messages du mois et sépare les pays', function (): void {
    ($this->template)();
    ($this->template)();
    ($this->template)(($this->foreign)('+33612345678'));

    expect(($this->usage)())->toBe([['2026-06-01', 'utility', 'BE', 2], ['2026-06-01', 'utility', 'FR', 1]]);
});

it('compte une réponse libre comme « service »', function (): void {
    $this->asOwner(fn () => WhatsappMessage::query()->create(['org_id' => $this->org->id, 'customer_id' => $this->customer->id, 'direction' => 'in', 'type' => 'text', 'body_enc' => 'Bonjour', 'status' => 'received']));

    app(TenantContext::class)->run($this->org->id, fn () => $this->sender->sendText($this->org, $this->customer, 'Bonjour !'));

    expect(($this->usage)())->toBe([['2026-06-01', 'service', 'BE', 1]]);
});

it('ouvre une nouvelle ligne au changement de mois', function (): void {
    ($this->template)();
    $this->travelTo(now()->addMonth());

    ($this->template)();

    expect(($this->usage)())->toBe([['2026-06-01', 'utility', 'BE', 1], ['2026-07-01', 'utility', 'BE', 1]]);
});

it('ne compte pas un envoi refusé ou en échec', function (): void {
    $this->asOwner(fn () => $this->customer->forceFill(['whatsapp_opted_out_at' => now()])->save());
    expect(fn () => ($this->template)())->toThrow(SendRefused::class);

    $this->asOwner(fn () => $this->customer->forceFill(['whatsapp_opted_out_at' => null])->save());
    $this->transport->failWith = '131000';
    expect(fn () => ($this->template)())->toThrow(TransportFailed::class);
    $this->transport->failWith = null;

    expect(($this->usage)())->toBe([]);
});

it('classe « XX » un numéro dont le pays est inconnu', function (): void {
    expect(WhatsappUsage::country('+32470123456'))->toBe('BE')->and(WhatsappUsage::country('pas-un-numero'))->toBe('XX');
});

it('n\'est visible que de l\'établissement concerné', function (): void {
    ($this->template)();
    $other = $this->asOwner(fn () => Organization::factory()->create());

    $seen = app(TenantContext::class)->run($other->id, fn () => DB::table('whatsapp_usage')->count());

    expect($seen)->toBe(0);
});

it('est conservé à la suppression du compte : des compteurs sans donnée personnelle', function (): void {
    expect(ErasureSteps::RETAINED)->toContain('whatsapp_usage')->and(ErasureSteps::ALL)->not->toContain('whatsapp_usage');
});
