<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Services;

use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Tenancy\Support\CurrentTenant;

/**
 * Accès à la progression de l'organisation courante (créée à la demande pour les comptes antérieurs à l'assistant).
 */
final class OnboardingProgressService
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function current(): OnboardingProgress
    {
        $organization = $this->current->organization() ?? abort(403);

        return OnboardingProgress::query()->firstOrCreate(['org_id' => $organization->id], ['steps' => []]);
    }
}
