<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Prestataire simulé (développement et tests) : aucun appel réseau, aucun vrai paiement.
 *
 * La page « hébergée » est une page de Nova (`/facturation/simulation`) où l'on choisit une carte valide ou refusée.
 * Jetons : `pm_fake_ok` (débit accepté), `pm_fake_declined` (débit refusé), `pm_fake_broken` (échec après acceptation à la saisie).
 * Les débits demandés sont consignés dans `$charges` pour les tests.
 */
final class FakeGateway implements PaymentGateway
{
    public const OK = 'pm_fake_ok';

    public const DECLINED = 'pm_fake_declined';

    /** @var list<array{method: string, amount: int, currency: string, key: string, description: string}> */
    public array $charges = [];

    /** Refus forcé du prochain débit (code d'échec), quel que soit le moyen de paiement. */
    public ?string $failNext = null;

    public function name(): string
    {
        return 'fake';
    }

    public function beginSetup(Organization $organization, string $returnUrl): SetupSession
    {
        $ref = 'seti_'.Str::lower(Str::random(20));
        Cache::put('fake_billing_setup:'.$ref, ['return' => $returnUrl, 'method' => null], 3600);

        return new SetupSession($ref, url('/facturation/simulation?ref='.$ref));
    }

    /**
     * Simule le choix du propriétaire sur la page du prestataire (appelée par la page de simulation et par les tests).
     *
     * @return string adresse de retour vers Nova
     */
    public function simulateChoice(string $ref, string $methodRef): string
    {
        $session = Cache::get('fake_billing_setup:'.$ref) ?? abort(404);
        Cache::put('fake_billing_setup:'.$ref, [...$session, 'method' => $methodRef], 3600);

        return $session['return'].(str_contains($session['return'], '?') ? '&' : '?').'setup_ref='.$ref;
    }

    public function completeSetup(string $setupRef): ?StoredMethod
    {
        $session = Cache::get('fake_billing_setup:'.$setupRef);
        $method = $session['method'] ?? null;

        if (! is_string($method)) {
            return null;
        }

        return new StoredMethod($method, 'visa', $method === self::DECLINED ? '0002' : '4242', 12, (int) date('Y') + 3);
    }

    public function charge(string $methodRef, int $amountCents, string $currency, string $idempotencyKey, string $description): ChargeResult
    {
        $this->charges[] = ['method' => $methodRef, 'amount' => $amountCents, 'currency' => $currency, 'key' => $idempotencyKey, 'description' => $description];

        if ($this->failNext !== null) {
            $code = $this->failNext;
            $this->failNext = null;

            return ChargeResult::failed($code);
        }

        return $methodRef === self::DECLINED ? ChargeResult::failed('card_declined') : ChargeResult::succeeded('ch_fake_'.Str::lower(Str::random(16)));
    }
}
