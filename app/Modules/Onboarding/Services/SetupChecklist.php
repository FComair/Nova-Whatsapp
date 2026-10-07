<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Services;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Booking\Models\Booking;
use App\Modules\Calendars\Models\ExternalCalendar;
use App\Modules\Identity\Models\User;
use App\Modules\Onboarding\Support\OnboardingSteps;

/**
 * Réglages > Démarrage : les tâches de mise en route regroupées en quatre catégories, sans rien recréer.
 *
 * Chaque tâche renvoie vers un écran qui existe déjà. Son état vient de données réelles, relues à chaque affichage :
 * - étapes de l'assistant : l'enregistrement de progression existant (`onboarding_progress`) ;
 * - autres tâches : calendrier relié, premier rendez-vous, abonnement choisi, double authentification (les mêmes
 *   critères que « Premiers pas » du tableau de bord).
 * Une tâche appartient à une seule catégorie.
 */
final class SetupChecklist
{
    public const DONE = 'done';

    public const CURRENT = 'current';

    public const TODO = 'todo';

    public const SOON = 'soon';

    /**
     * Catégories et tâches, dans l'ordre d'affichage. `wizard` : étape de l'assistant ; `check` : tâche détectée dans les données.
     *
     * @var array<string, list<array{key: string, wizard?: string, check?: string, path?: string, optional?: bool}>>
     */
    private const CATEGORIES = [
        'business' => [
            ['key' => 'company', 'wizard' => OnboardingSteps::COMPANY],
            ['key' => 'locations', 'wizard' => OnboardingSteps::LOCATIONS],
            ['key' => 'hours', 'wizard' => OnboardingSteps::HOURS],
        ],
        'team' => [
            ['key' => 'services', 'wizard' => OnboardingSteps::SERVICES],
            ['key' => 'practitioners', 'wizard' => OnboardingSteps::PRACTITIONERS],
            ['key' => 'policies', 'wizard' => OnboardingSteps::POLICIES],
            ['key' => 'calendar', 'check' => 'calendar', 'path' => '/calendriers', 'optional' => true],
        ],
        'messages' => [
            ['key' => 'whatsapp', 'wizard' => OnboardingSteps::WHATSAPP],
            ['key' => 'templates', 'wizard' => OnboardingSteps::TEMPLATES],
        ],
        'launch' => [
            ['key' => 'test_booking', 'wizard' => OnboardingSteps::TEST_BOOKING],
            ['key' => 'first_booking', 'check' => 'first_booking', 'path' => '/planning'],
            ['key' => 'plan', 'check' => 'plan', 'path' => '/facturation'],
            ['key' => 'two_factor', 'check' => 'two_factor', 'path' => '/securite'],
        ],
    ];

    public function __construct(private readonly OnboardingProgressService $progress) {}

    /** @return list<string> */
    public static function categoryKeys(): array
    {
        return array_keys(self::CATEGORIES);
    }

    /**
     * @return list<array{key: string, steps: list<array{key: string, state: string, optional: bool, href: string|null, wizard: bool}>, total: int, done: int, left: int, complete: bool}>
     */
    public function categories(User $user): array
    {
        $progress = $this->progress->current();
        $current = $progress->currentStep();
        $detected = [
            'calendar' => ExternalCalendar::query()->exists(),
            'first_booking' => Booking::query()->exists(),
            'plan' => Subscription::query()->exists(),
            'two_factor' => $user->hasTwoFactor(),
        ];

        $categories = [];

        foreach (self::CATEGORIES as $key => $definitions) {
            $steps = [];

            foreach ($definitions as $definition) {
                $steps[] = isset($definition['wizard'])
                    ? $this->wizardStep($definition, $progress->isDone($definition['wizard']), $definition['wizard'] === $current)
                    : [
                        'key' => $definition['key'],
                        'state' => $detected[$definition['check']] ? self::DONE : self::TODO,
                        'optional' => $definition['optional'] ?? false,
                        'href' => $definition['path'],
                        'wizard' => false,
                    ];
            }

            // « Reste à faire » : les tâches obligatoires pas encore faites (ni facultatives, ni « bientôt »).
            $left = count(array_filter($steps, fn (array $step): bool => ! $step['optional'] && ! in_array($step['state'], [self::DONE, self::SOON], true)));

            $categories[] = [
                'key' => $key,
                'steps' => $steps,
                'total' => count($steps),
                'done' => count(array_filter($steps, fn (array $step): bool => $step['state'] === self::DONE)),
                'left' => $left,
                'complete' => $left === 0,
            ];
        }

        return $categories;
    }

    /**
     * @param  array{key: string, wizard?: string}  $definition
     * @return array{key: string, state: string, optional: bool, href: string|null, wizard: bool}
     */
    private function wizardStep(array $definition, bool $done, bool $isCurrent): array
    {
        $wizard = (string) $definition['wizard'];
        $state = match (true) {
            ! OnboardingSteps::isImplemented($wizard) => self::SOON,
            $done => self::DONE,
            $isCurrent => self::CURRENT,
            default => self::TODO,
        };

        return [
            'key' => $definition['key'],
            'state' => $state,
            'optional' => false,
            'href' => $state === self::SOON ? null : route('onboarding.step', ['slug' => OnboardingSteps::SLUGS[$wizard]], false),
            'wizard' => true,
        ];
    }
}
