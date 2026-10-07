<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\DaySummary;
use App\Modules\Calendars\Models\ExternalCalendar;
use App\Modules\Identity\Models\User;
use App\Modules\Onboarding\Services\OnboardingProgressService;
use App\Modules\Onboarding\Support\OnboardingSteps;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Services\PractitionerManager;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord : résumé du jour (rendez-vous, prochain rendez-vous, compteurs), « Premiers pas » du propriétaire,
 * rappel de fin d'essai et reprise de l'assistant d'onboarding (flowcharts 1B à 1D, 2B).
 */
final class DashboardController
{
    public function __invoke(Request $request, CurrentTenant $current, OnboardingProgressService $progress, PractitionerManager $staff, DaySummary $summary): Response
    {
        $organization = $current->organization() ?? abort(403);
        $onboarding = null;
        $profile = null;
        $firstSteps = null;

        // Le propriétaire est invité à reprendre l'assistant tant qu'il n'est pas terminé (mis en pause, flowcharts 1B à 1D).
        if ($current->role() === OrgMember::ROLE_OWNER && ! ($state = $progress->current())->isComplete()) {
            $onboarding = ['slug' => OnboardingSteps::SLUGS[$state->currentStep()]];
        }

        // Un praticien invité complète son propre profil (services et disponibilités) pour recevoir des rendez-vous.
        if ($current->role() === OrgMember::ROLE_PRACTITIONER) {
            $practitioner = Practitioner::query()->where('user_id', auth()->id())->first();
            $profile = $practitioner === null ? null : ['complete' => $staff->isBookable($practitioner)];
        }

        if ($current->role() === OrgMember::ROLE_OWNER) {
            $firstSteps = $this->firstSteps($request);
        }

        return Inertia::render('Dashboard', [
            'onboarding' => $onboarding,
            'profile' => $profile,
            'summary' => $summary->forToday($organization),
            'firstSteps' => $firstSteps,
            'trial' => $this->trial($organization),
        ]);
    }

    /**
     * « Premiers pas » du propriétaire : disparaît quand tout est fait.
     *
     * @return list<array{key: string, done: bool}>|null
     */
    private function firstSteps(Request $request): ?array
    {
        $user = $request->user();

        $steps = [
            ['key' => 'whatsapp', 'done' => WhatsappAccount::query()->where('status', 'active')->exists()],
            ['key' => 'two_factor', 'done' => $user instanceof User && $user->hasTwoFactor()],
            ['key' => 'first_booking', 'done' => Booking::query()->exists()],
            ['key' => 'calendar', 'done' => ExternalCalendar::query()->exists()],
            ['key' => 'billing', 'done' => Subscription::query()->exists()],
        ];

        return in_array(false, array_column($steps, 'done'), true) ? $steps : null;
    }

    /**
     * Jours d'essai restants, pour un compte encore en essai.
     *
     * @return array{days: int}|null
     */
    private function trial(Organization $organization): ?array
    {
        if ($organization->status !== Organization::STATUS_TRIAL || $organization->trial_ends_at === null) {
            return null;
        }

        return ['days' => max(0, (int) ceil(now()->diffInHours($organization->trial_ends_at, false) / 24))];
    }
}
