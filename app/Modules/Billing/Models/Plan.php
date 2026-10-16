<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Formule d'abonnement (docs/10 et docs/40). Table globale en lecture seule pour l'application cliente ; gérée par le Super Admin
 * (voir PlatformPlan et PlanManager).
 *
 * Une formule qui a des abonnés n'est jamais modifiée sur place pour ce qui est commercial (prix, période, TVA, frais, limites) : une nouvelle
 * version est créée et l'ancienne (`superseded_by`) garde son prix et ses limites pour ses abonnés.
 *
 * @property string $id
 * @property string $family_id
 * @property int $version
 * @property string|null $superseded_by
 * @property string $code
 * @property string $name
 * @property string $status draft, active, inactive ou archived
 * @property string $plan_type free_trial, monthly, annual, custom ou one_time
 * @property string $billing_frequency monthly, quarterly, yearly ou custom
 * @property int $billing_interval_months
 * @property int $price_cents prix d'UNE période de facturation
 * @property int $price_monthly_cents équivalent mensuel
 * @property int $setup_fee_cents
 * @property string $tax_mode excluded ou included
 * @property int|null $trial_days
 * @property string $currency
 * @property array<string, int|null> $limits
 * @property array<int, array{label: array<string, string>, state: string, value: string|null}> $features
 * @property string|null $internal_description
 * @property array<string, string>|null $public_description
 * @property string $visibility public, private, invite_only ou segments
 * @property string|null $invite_code
 * @property array{sectors?: list<string>, countries?: list<string>}|null $segments
 * @property string $cta_type choose_plan, start_trial ou contact_sales
 * @property string|null $cta_url
 * @property Carbon|null $available_from
 * @property Carbon|null $available_until
 * @property int $position
 * @property bool $is_active reflet de `status = active` (conservé pour l'existant)
 * @property bool $is_recommended désignée explicitement par la plateforme (au plus une)
 */
class Plan extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::DRAFT, self::ACTIVE, self::INACTIVE, self::ARCHIVED];

    public const TYPES = ['free_trial', 'monthly', 'annual', 'custom', 'one_time'];

    /** Types que la facturation sait encaisser : un abonnement récurrent à période fixe. */
    public const BILLABLE_TYPES = ['monthly', 'annual'];

    public const FREQUENCIES = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    public const VISIBILITIES = ['public', 'private', 'invite_only', 'segments'];

    public const CTA_TYPES = ['choose_plan', 'start_trial', 'contact_sales'];

    public const FEATURE_STATES = ['included', 'limited', 'unavailable'];

    public const LIMIT_KEYS = ['practitioners', 'locations', 'conversations', 'appointments'];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limits' => 'array', 'features' => 'array', 'public_description' => 'array', 'segments' => 'array',
            'is_active' => 'boolean', 'is_recommended' => 'boolean',
            'available_from' => 'datetime', 'available_until' => 'datetime',
        ];
    }

    /** Durée d'une période de facturation, en mois. */
    public function interval(): int
    {
        return max(1, (int) $this->billing_interval_months);
    }

    /** Une formule se souscrit en ligne seulement si elle est récurrente, à période fixe et payante. */
    public function purchasable(): bool
    {
        return in_array($this->plan_type, self::BILLABLE_TYPES, true) && $this->price_cents > 0;
    }

    /** Montant HORS TVA d'une période : le prix saisi, ou ce prix ramené hors taxe quand il est affiché TVA comprise. */
    public function netCents(int $vatRatePercent): int
    {
        return $this->tax_mode === 'included' ? (int) round($this->price_cents / (1 + $vatRatePercent / 100)) : $this->price_cents;
    }

    /** Frais de mise en service hors TVA. */
    public function netSetupFeeCents(int $vatRatePercent): int
    {
        return $this->tax_mode === 'included' ? (int) round($this->setup_fee_cents / (1 + $vatRatePercent / 100)) : $this->setup_fee_cents;
    }

    public function inAvailabilityWindow(?Carbon $at = null): bool
    {
        $at ??= now();

        return ($this->available_from === null || $this->available_from <= $at) && ($this->available_until === null || $this->available_until >= $at);
    }

    /** Texte public dans la langue demandée, à défaut dans la langue de repli. */
    public function publicDescription(string $locale): ?string
    {
        $texts = (array) $this->public_description;
        $text = $texts[$locale] ?? $texts[(string) config('app.fallback_locale')] ?? null;

        return filled($text) ? (string) $text : null;
    }

    /**
     * Formules proposables à de NOUVEAUX abonnements : actives, non remplacées par une version plus récente, dans leur fenêtre de dates.
     * La visibilité (publique, privée, sur invitation, segments) est jugée à part, par PlanCatalog.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)->whereNull('superseded_by')
            ->where(fn (Builder $inner) => $inner->whereNull('available_from')->orWhere('available_from', '<=', now()))
            ->where(fn (Builder $inner) => $inner->whereNull('available_until')->orWhere('available_until', '>=', now()));
    }
}
