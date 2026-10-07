<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Http\Controllers;

use App\Modules\Onboarding\Services\SetupChecklist;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Réglages > Démarrage et mise en route : une page par catégorie, qui liste les tâches et renvoie vers les écrans existants.
 * Propriétaire uniquement, comme l'assistant d'onboarding (imposé par la route).
 */
final class SetupController
{
    public function __construct(private readonly SetupChecklist $checklist) {}

    public function show(Request $request, string $category): Response
    {
        abort_unless(in_array($category, SetupChecklist::categoryKeys(), true), 404);

        $current = collect($this->checklist->categories($request->user() ?? abort(403)))->firstWhere('key', $category);

        return Inertia::render('Account/Setup', ['category' => $current]);
    }
}
