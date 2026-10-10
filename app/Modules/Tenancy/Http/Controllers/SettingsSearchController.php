<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Services\SettingsSearchIndex;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * Index de la recherche dans les Réglages : titres, descriptions et mots-clés dans les trois langues, limités aux pages
 * que le rôle de la personne connectée peut ouvrir. La recherche elle-même se fait dans le navigateur ; rien ne se modifie ici.
 */
final class SettingsSearchController
{
    public function __invoke(CurrentTenant $current, SettingsSearchIndex $index): JsonResponse
    {
        return response()->json(['entries' => $index->forRole($current->role(), $current->permissions())])
            ->header('Cache-Control', 'private, no-store');
    }
}
