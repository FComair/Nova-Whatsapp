<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Support;

use App\Modules\Tenancy\Support\CurrentTenant;

/**
 * Droits sur la fidélité (docs/27, docs/02 §1) :
 * - configurer le programme et ses messages : propriétaire ;
 * - tableau de bord, liste des clients, rapports : propriétaire, responsable de site et réceptionniste (lecture) ;
 * - utiliser une récompense : propriétaire, responsable de site, réceptionniste ;
 * - ajuster des tampons, annuler une utilisation, inscrire ou retirer un client : propriétaire et responsable de site (motif obligatoire) ;
 * - praticien : voit la progression d'un client dont il s'occupe (fiche et rendez-vous), sans aucune action.
 */
final class LoyaltyAccess
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function role(): string
    {
        return $this->current->role() ?? abort(403);
    }

    public function canConfigure(): bool
    {
        return $this->current->can('loyalty.configure');
    }

    public function canView(): bool
    {
        return $this->current->can('loyalty.view');
    }

    public function canRedeem(): bool
    {
        return $this->current->can('loyalty.redeem');
    }

    public function canAdjust(): bool
    {
        return $this->current->can('loyalty.adjust');
    }

    /** Voit la progression sur la fiche d'un client et dans un rendez-vous (tous les rôles ; le praticien seulement pour ses clients, géré par l'écran appelant). */
    public function canSeeProgress(): bool
    {
        return true;
    }

    /**
     * @return array{view: bool, configure: bool, redeem: bool, adjust: bool}
     */
    public function abilities(): array
    {
        return ['view' => $this->canView(), 'configure' => $this->canConfigure(), 'redeem' => $this->canRedeem(), 'adjust' => $this->canAdjust()];
    }
}
