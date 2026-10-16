<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Réglages › Apparence : thème clair, sombre ou celui du système. Tous les rôles ; le choix reste dans le navigateur
 * (aucune donnée serveur), il est donc conservé à la fermeture de l'application et à la reconnexion sur le même appareil.
 */
final class AppearanceController
{
    public function show(): Response
    {
        return Inertia::render('Account/Appearance');
    }
}
