<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Models;

use App\Modules\Onboarding\Support\OnboardingSteps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Progression de l'assistant d'une organisation. Enregistrée à chaque étape : l'assistant est reprenable.
 *
 * @property string $org_id
 * @property array<string, string> $steps
 * @property Carbon|null $completed_at
 * @property Carbon|null $went_live_at
 */
class OnboardingProgress extends Model
{
    protected $table = 'onboarding_progress';

    protected $primaryKey = 'org_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'went_live_at' => 'datetime',
        ];
    }

    public function isDone(string $step): bool
    {
        return ($this->steps[$step] ?? null) === 'done';
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Première étape non terminée : là où l'assistant se rouvre à la prochaine connexion (flowchart 2A).
     */
    public function currentStep(): string
    {
        foreach (OnboardingSteps::ALL as $step) {
            if (! $this->isDone($step)) {
                return $step;
            }
        }

        return OnboardingSteps::TEST_BOOKING;
    }

    /**
     * Étape vers laquelle aller une fois `$step` terminée : la prochaine étape non faite qui la suit ;
     * à défaut la première étape non faite (une étape sautée plus tôt) ; à défaut la dernière.
     */
    public function nextStepAfter(string $step): string
    {
        $following = array_slice(OnboardingSteps::ALL, OnboardingSteps::number($step));

        foreach ($following as $candidate) {
            if (! $this->isDone($candidate)) {
                return $candidate;
            }
        }

        return $this->currentStep();
    }

    public function markDone(string $step): void
    {
        $steps = $this->steps;
        $steps[$step] = 'done';
        $this->steps = $steps;

        if ($this->completed_at === null && collect(OnboardingSteps::ALL)->every(fn (string $s): bool => ($steps[$s] ?? null) === 'done')) {
            $this->completed_at = now();
        }

        $this->save();
    }

    /**
     * État de chaque étape pour l'affichage : done, current, todo ou soon (écran pas encore construit).
     *
     * @return list<array{key: string, number: int, slug: string, status: string, available: bool}>
     */
    public function overview(): array
    {
        $current = $this->currentStep();

        return collect(OnboardingSteps::ALL)->map(fn (string $step): array => [
            'key' => $step,
            'number' => OnboardingSteps::number($step),
            'slug' => OnboardingSteps::SLUGS[$step],
            'status' => match (true) {
                $this->isDone($step) => 'done',
                $step === $current => 'current',
                default => 'todo',
            },
            'available' => OnboardingSteps::isImplemented($step),
        ])->all();
    }
}
