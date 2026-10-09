<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Programme de fidélité d'un établissement (docs/27). Un seul programme non archivé à la fois.
 *
 * @property string $id
 * @property string $org_id
 * @property string $status draft, active, paused ou archived
 * @property array<string, string> $name
 * @property array<string, string> $reward_description
 * @property array<string, string> $terms
 * @property int $required_visits
 * @property string $services_mode all ou selected
 * @property int|null $min_amount_cents
 * @property bool $require_payment
 * @property string $reward_type free_service, percent_discount, fixed_amount ou custom
 * @property int|null $reward_value pourcentage (1 à 100) ou centimes selon le type
 * @property int|null $reward_max_cents
 * @property string $reward_services_mode all ou selected
 * @property string $redeem_timing next_visit ou any_time
 * @property bool $combinable
 * @property bool $requires_appointment
 * @property int|null $expiry_days
 * @property int $expiry_reminder_days
 * @property bool $auto_enroll
 * @property array<string, mixed> $messages
 * @property Carbon|null $activated_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $archived_at
 */
final class LoyaltyProgram extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::DRAFT, self::ACTIVE, self::PAUSED, self::ARCHIVED];

    public const REWARD_TYPES = ['free_service', 'percent_discount', 'fixed_amount', 'custom'];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'reward_description' => 'array',
            'terms' => 'array',
            'messages' => 'array',
            'require_payment' => 'boolean',
            'combinable' => 'boolean',
            'requires_appointment' => 'boolean',
            'auto_enroll' => 'boolean',
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** Le programme en cours (brouillon, actif ou en pause) ; null s'il n'y en a pas encore. */
    public static function current(): ?self
    {
        return self::query()->where('status', '!=', self::ARCHIVED)->first();
    }

    /** Le programme qui compte les visites en ce moment ; null s'il est brouillon, en pause ou archivé. */
    public static function earning(): ?self
    {
        return self::query()->where('status', self::ACTIVE)->first();
    }

    public function isEarning(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Texte d'un champ multilingue dans la langue demandée ; à défaut français, puis la première langue renseignée. */
    public function text(string $field, string $locale): string
    {
        /** @var array<string, string> $values */
        $values = (array) $this->getAttribute($field);

        foreach ([$locale, 'fr', ...array_keys($values)] as $candidate) {
            $value = trim((string) ($values[$candidate] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Services qui comptent (`earn`) ou visés par la récompense (`reward`) ; null = tous les services.
     *
     * @return list<string>|null
     */
    public function serviceIds(string $kind): ?array
    {
        $mode = $kind === 'earn' ? $this->services_mode : $this->reward_services_mode;

        if ($mode !== 'selected') {
            return null;
        }

        /** @var list<string> $ids */
        $ids = DB::table('loyalty_program_services')->where('program_id', $this->id)->where('kind', $kind)->pluck('service_id')->map(fn ($id): string => (string) $id)->all();

        return $ids;
    }

    /**
     * Instantané de la récompense au moment où elle est attribuée : la récompense reste ce qu'elle était à ce moment-là.
     *
     * @return array<string, mixed>
     */
    public function rewardSnapshot(): array
    {
        return [
            'type' => $this->reward_type,
            'value' => $this->reward_value,
            'max_cents' => $this->reward_max_cents,
            'description' => $this->reward_description,
            'terms' => $this->terms,
            'program_name' => $this->name,
            'required_visits' => $this->required_visits,
            'service_ids' => $this->serviceIds('reward'),
            'redeem_timing' => $this->redeem_timing,
            'combinable' => $this->combinable,
            'requires_appointment' => $this->requires_appointment,
        ];
    }
}
