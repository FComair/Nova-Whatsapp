<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

use App\Modules\Billing\Support\ChargeResult;
use App\Modules\Billing\Support\SetupSession;
use App\Modules\Billing\Support\StoredMethod;
use App\Modules\Tenancy\Models\Organization;

/**
 * Prestataire de paiement (choix du fondateur encore ouvert). Rendivoo pilote la facturation elle-même : le prestataire ne sert
 * qu'à (1) recueillir un moyen de paiement sur SA page sécurisée et (2) débiter ce moyen. Aucune donnée de carte ne transite
 * par Rendivoo : seulement un jeton.
 */
interface PaymentGateway
{
    /** Identifiant du prestataire, enregistré avec chaque abonnement (`fake`, puis le prestataire retenu). */
    public function name(): string;

    /**
     * Ouvre la page hébergée où le propriétaire saisit son moyen de paiement.
     *
     * @param  string  $returnUrl  adresse de Rendivoo où le propriétaire revient ; le prestataire y ajoute `setup_ref`
     */
    public function beginSetup(Organization $organization, string $returnUrl): SetupSession;

    /**
     * Récupère le moyen de paiement saisi, à partir de la référence de la session. Renvoie null si la saisie n'a pas abouti.
     */
    public function completeSetup(string $setupRef): ?StoredMethod;

    /**
     * Débite un moyen de paiement enregistré. `$idempotencyKey` évite un double débit en cas de nouvelle tentative technique.
     */
    public function charge(string $methodRef, int $amountCents, string $currency, string $idempotencyKey, string $description): ChargeResult;
}
