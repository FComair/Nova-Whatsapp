<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerConsent;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Consentements d'un client : chaque changement est une nouvelle ligne datée (journal en ajout seul), jamais une modification.
 *
 * Deux finalités : `appointment` (confirmations, rappels, changements) et `marketing` (messages promotionnels, qui n'existent pas
 * encore dans Rendivoo mais dont le consentement est déjà consigné). Un STOP reçu sur WhatsApp retire les deux ; un START ne rend que
 * `appointment` : le marketing demande toujours un accord explicite.
 */
final class ConsentRecorder
{
    public const PURPOSES = ['appointment', 'marketing'];

    public function record(Customer $customer, string $purpose, string $status, string $source, ?string $actorId = null, ?string $evidence = null, string $channel = 'whatsapp'): CustomerConsent
    {
        if (! in_array($purpose, self::PURPOSES, true) || ! in_array($status, ['granted', 'withdrawn'], true)) {
            throw new InvalidArgumentException('Finalité ou état de consentement inconnu.');
        }

        return CustomerConsent::query()->create([
            'org_id' => $customer->org_id,
            'customer_id' => $customer->id,
            'purpose' => $purpose,
            'channel' => $channel,
            'status' => $status,
            'source' => $source,
            'evidence_ref' => $evidence === null ? null : mb_substr($evidence, 0, 120),
            'recorded_by' => $actorId,
            'recorded_at' => now(),
        ]);
    }

    /** STOP : plus aucun message WhatsApp, ni rendez-vous ni marketing. */
    public function recordStop(Customer $customer): void
    {
        foreach (self::PURPOSES as $purpose) {
            $this->record($customer, $purpose, 'withdrawn', 'stop_reply');
        }
    }

    /** START : les messages de rendez-vous reprennent ; le marketing reste retiré tant qu'il n'est pas redemandé. */
    public function recordStart(Customer $customer): void
    {
        $this->record($customer, 'appointment', 'granted', 'start_reply');
    }

    public function current(Customer $customer, string $purpose, string $channel = 'whatsapp'): ?CustomerConsent
    {
        return CustomerConsent::query()
            ->where('customer_id', $customer->id)->where('purpose', $purpose)->where('channel', $channel)
            ->orderByDesc('recorded_at')->orderByDesc('id')->first();
    }

    /** État du marketing WhatsApp tel que la liste le montre : opted_in, opted_out ou unknown. */
    public function whatsappStatus(Customer $customer): string
    {
        if ($customer->whatsapp_opted_out_at !== null) {
            return 'opted_out';
        }

        return match ($this->current($customer, 'marketing')?->status) {
            'granted' => 'opted_in',
            'withdrawn' => 'opted_out',
            default => 'unknown',
        };
    }

    /** Un message promotionnel n'est permis qu'à un client qui a dit oui, qui n'est pas bloqué ni archivé. */
    public function canSendMarketing(Customer $customer): bool
    {
        return $customer->status === 'active' && $this->whatsappStatus($customer) === 'opted_in';
    }

    /**
     * @return Collection<int, CustomerConsent>
     */
    public function history(Customer $customer): Collection
    {
        return CustomerConsent::query()->where('customer_id', $customer->id)->orderByDesc('recorded_at')->orderByDesc('id')->get();
    }
}
