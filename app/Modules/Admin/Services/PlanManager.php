<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformPlan;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Billing\Models\Plan;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Gestion des formules par le Super Admin (docs/40) : création, modification, publication, duplication, archivage, suppression et
 * migration des abonnés, avec les garde-fous qui protègent les abonnements en cours.
 *
 * Règles :
 *  - un brouillon n'est jamais visible des clients ; une formule archivée n'est plus proposée mais reste consultable ici ;
 *  - modifier le prix, la devise, la période, la TVA, les frais ou les limites d'une formule qui a des abonnés crée une NOUVELLE VERSION :
 *    l'ancienne garde son prix et ses limites pour eux (le reste — textes, fonctionnalités, ordre, visibilité, dates — se modifie sur place) ;
 *  - une formule référencée par des abonnés ou des comptes ne se supprime pas ; leurs abonnés se migrent d'abord, explicitement, et le
 *    changement est programmé à leur prochaine échéance ;
 *  - un paiement unique ne se publie pas : la facturation ne sait pas encore l'encaisser.
 * Chaque opération vérifie la permission, tient dans une transaction et est tracée dans le journal d'audit.
 */
final class PlanManager
{
    private const PERMISSION = 'settings.edit';

    /** Champs commerciaux : les changer pour une formule qui a des abonnés crée une nouvelle version. */
    public const COMMERCIAL = ['price_cents', 'currency', 'billing_interval_months', 'billing_frequency', 'tax_mode', 'setup_fee_cents', 'limits', 'plan_type'];

    /** @var list<string> */
    public const CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $adminId): PlatformPlan
    {
        AdminAccess::authorize($adminId, self::PERMISSION);
        $attributes = $this->normalize($data);

        return $this->db()->transaction(function () use ($attributes, $data, $adminId): PlatformPlan {
            $status = $attributes['status'] ?? Plan::DRAFT;
            $this->assertPublishable($attributes, $status);
            $id = (string) Str::uuid7();
            $attributes += [
                'id' => $id,
                'family_id' => $id,
                'code' => $this->newCode((string) ($data['code'] ?? '') ?: (string) $attributes['name']),
                'position' => $attributes['position'] ?? ((int) $this->db()->table('plans')->max('position') + 1),
                'status' => $status,
            ];

            $this->releaseRecommended($attributes);
            $plan = PlatformPlan::query()->create($attributes);
            PlatformAudit::record($adminId, 'plan.created', null, 'plans', $plan->id, ['code' => $plan->code, 'name' => $plan->name, 'status' => $plan->status]);

            return $plan;
        });
    }

    /**
     * Modifie une formule. Renvoie la formule en vigueur après la modification : la même, ou sa nouvelle version si le changement est
     * commercial et que la formule a des abonnés.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PlatformPlan $plan, array $data, string $adminId): PlatformPlan
    {
        AdminAccess::authorize($adminId, self::PERMISSION);

        if ($plan->superseded_by !== null) {
            throw new DomainException(__('plans.errors.superseded'));
        }

        $attributes = $this->normalize($data + $this->current($plan));
        unset($attributes['code']);

        return $this->db()->transaction(function () use ($plan, $attributes, $adminId): PlatformPlan {
            $status = $attributes['status'] ?? $plan->status;
            $this->assertPublishable($attributes + $plan->getAttributes(), $status);
            $this->releaseRecommended($attributes, $plan->id);

            if ($this->isCommercialChange($plan, $attributes) && $this->subscribers($plan) > 0) {
                return $this->fork($plan, $attributes, $adminId);
            }

            $plan->forceFill($attributes);
            $diff = collect($plan->getDirty())->except(['updated_at'])->keys()->all();

            if ($diff !== []) {
                $plan->save();
                PlatformAudit::record($adminId, 'plan.updated', null, 'plans', $plan->id, ['code' => $plan->code, 'fields' => $diff]);
            }

            return $plan;
        });
    }

    /** Copie en brouillon, avec son propre code, hors de toute famille de versions. */
    public function duplicate(PlatformPlan $plan, string $adminId): PlatformPlan
    {
        AdminAccess::authorize($adminId, self::PERMISSION);

        return $this->db()->transaction(function () use ($plan, $adminId): PlatformPlan {
            $copy = $this->create([
                ...$this->current($plan),
                'name' => $this->copyName($plan->name),
                'code' => null,
                'status' => Plan::DRAFT,
                'is_recommended' => false,
                'invite_code' => null,
                'position' => (int) $this->db()->table('plans')->max('position') + 1,
            ], $adminId);
            PlatformAudit::record($adminId, 'plan.duplicated', null, 'plans', $copy->id, ['from' => $plan->code, 'code' => $copy->code]);

            return $copy;
        });
    }

    /** Publie (actif), retire provisoirement (inactif), archive ou remet en brouillon. */
    public function setStatus(PlatformPlan $plan, string $status, string $adminId): void
    {
        AdminAccess::authorize($adminId, self::PERMISSION);

        if (! in_array($status, Plan::STATUSES, true)) {
            throw new InvalidArgumentException(__('plans.errors.unknown_status'));
        }

        if ($plan->superseded_by !== null) {
            throw new DomainException(__('plans.errors.superseded'));
        }

        if ($plan->status === $status) {
            return;
        }

        if ($status === Plan::DRAFT && $this->subscribers($plan) > 0) {
            throw new DomainException(__('plans.errors.draft_has_subscribers'));
        }

        $this->db()->transaction(function () use ($plan, $status, $adminId): void {
            if ($status === Plan::ACTIVE) {
                $this->assertPublishable($plan->getAttributes(), $status);
            }

            $from = $plan->status;
            $plan->forceFill(['status' => $status] + (in_array($status, [Plan::ARCHIVED, Plan::DRAFT], true) ? ['is_recommended' => false] : []))->save();
            PlatformAudit::record($adminId, 'plan.'.($status === Plan::ARCHIVED ? 'archived' : ($status === Plan::ACTIVE ? 'published' : 'status_changed')), null, 'plans', $plan->id, [
                'code' => $plan->code, 'from' => $from, 'to' => $status, 'subscribers' => $this->subscribers($plan),
            ]);
        });
    }

    /**
     * Suppression définitive. Refusée tant qu'un abonnement ou un compte référence la formule : il faut d'abord migrer ses abonnés.
     */
    public function delete(PlatformPlan $plan, string $adminId): void
    {
        AdminAccess::authorize($adminId, self::PERMISSION);
        $references = $this->references($plan);

        if ($references['subscriptions'] > 0 || $references['organizations'] > 0) {
            throw new DomainException(__('plans.errors.has_subscribers', ['count' => $references['subscriptions'] + $references['organizations']]));
        }

        if ($plan->status === Plan::ACTIVE) {
            throw new DomainException(__('plans.errors.delete_active'));
        }

        $this->db()->transaction(function () use ($plan, $adminId): void {
            PlatformAudit::record($adminId, 'plan.deleted', null, 'plans', $plan->id, ['code' => $plan->code, 'name' => $plan->name, 'status' => $plan->status]);
            $plan->delete();
        });
    }

    /**
     * Programme le passage des abonnés de `$from` à `$to` à leur PROCHAINE échéance (jamais immédiatement, jamais avec un prélèvement
     * surprise : le prix de `$to` s'applique alors).
     *
     * @return int nombre d'abonnements programmés
     */
    public function migrateSubscribers(PlatformPlan $from, PlatformPlan $to, string $adminId): int
    {
        AdminAccess::authorize($adminId, self::PERMISSION);

        if ($from->id === $to->id) {
            throw new InvalidArgumentException(__('plans.errors.same_plan'));
        }

        if ($to->status !== Plan::ACTIVE || $to->superseded_by !== null || ! $to->purchasable()) {
            throw new DomainException(__('plans.errors.target_unavailable'));
        }

        return $this->db()->transaction(function () use ($from, $to, $adminId): int {
            $count = (int) $this->db()->selectOne('select plan_schedule_migration(?, ?) as n', [$from->id, $to->id])->n;
            PlatformAudit::record($adminId, 'plan.subscribers_migrated', null, 'plans', $from->id, ['from' => $from->code, 'to' => $to->code, 'subscriptions' => $count]);

            return $count;
        });
    }

    /**
     * Nouvel ordre d'affichage : la position de chaque formule suit sa place dans la liste.
     *
     * @param  list<string>  $orderedIds
     */
    public function reorder(array $orderedIds, string $adminId): void
    {
        AdminAccess::authorize($adminId, self::PERMISSION);

        $this->db()->transaction(function () use ($orderedIds, $adminId): void {
            foreach (array_values($orderedIds) as $index => $id) {
                $this->db()->table('plans')->where('id', $id)->update(['position' => $index + 1]);
            }

            PlatformAudit::record($adminId, 'plan.reordered', null, 'plans', null, ['count' => count($orderedIds)]);
        });
    }

    /** Abonnés actuels : abonnements non terminés sur cette formule, ou programmés vers elle. */
    public function subscribers(Plan $plan): int
    {
        return (int) $this->db()->table('subscriptions')->where('status', '<>', 'canceled')->where(fn ($query) => $query->where('plan_id', $plan->id)->orWhere('pending_plan_id', $plan->id))->count();
    }

    /**
     * @return array{subscriptions: int, organizations: int}
     */
    public function references(Plan $plan): array
    {
        return [
            'subscriptions' => (int) $this->db()->table('subscriptions')->where(fn ($query) => $query->where('plan_id', $plan->id)->orWhere('pending_plan_id', $plan->id))->count(),
            'organizations' => (int) $this->db()->table('organizations')->where('plan_id', $plan->id)->count(),
        ];
    }

    /**
     * Entrées du formulaire ramenées à des colonnes valides. Lance une exception au message clair pour tout champ invalide.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 60) {
            throw new InvalidArgumentException(__('plans.errors.name'));
        }

        $type = (string) ($data['plan_type'] ?? 'monthly');
        $frequency = (string) ($data['billing_frequency'] ?? 'monthly');

        if (! in_array($type, Plan::TYPES, true) || ! in_array($frequency, [...array_keys(Plan::FREQUENCIES), 'custom'], true)) {
            throw new InvalidArgumentException(__('plans.errors.type'));
        }

        $interval = $frequency === 'custom' ? (int) ($data['billing_interval_months'] ?? 0) : Plan::FREQUENCIES[$frequency];

        if ($interval < 1 || $interval > 60) {
            throw new InvalidArgumentException(__('plans.errors.interval'));
        }

        $price = $this->cents($data['price_cents'] ?? 0, 'plans.errors.price');
        $setup = $this->cents($data['setup_fee_cents'] ?? 0, 'plans.errors.setup_fee');
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'EUR')));

        if (! in_array($currency, self::CURRENCIES, true)) {
            throw new InvalidArgumentException(__('plans.errors.currency'));
        }

        $taxMode = (string) ($data['tax_mode'] ?? 'excluded');
        $visibility = (string) ($data['visibility'] ?? 'public');
        $cta = (string) ($data['cta_type'] ?? $this->defaultCta($type));

        if (! in_array($taxMode, ['excluded', 'included'], true) || ! in_array($visibility, Plan::VISIBILITIES, true) || ! in_array($cta, Plan::CTA_TYPES, true)) {
            throw new InvalidArgumentException(__('plans.errors.type'));
        }

        $trial = $data['trial_days'] ?? null;
        $trial = $trial === null || $trial === '' ? null : (int) $trial;

        if ($trial !== null && ($trial < 0 || $trial > 365)) {
            throw new InvalidArgumentException(__('plans.errors.trial'));
        }

        $segments = ['sectors' => array_values(array_filter((array) ($data['segments']['sectors'] ?? []))), 'countries' => array_values(array_filter((array) ($data['segments']['countries'] ?? [])))];
        $invite = filled($data['invite_code'] ?? null) ? trim((string) $data['invite_code']) : null;

        if ($visibility === 'segments' && $segments['sectors'] === [] && $segments['countries'] === []) {
            throw new InvalidArgumentException(__('plans.errors.segments'));
        }

        if ($visibility === 'invite_only') {
            $invite ??= Str::upper(Str::random(10));
        } else {
            $invite = null;
        }

        $from = $this->date($data['available_from'] ?? null);
        $until = $this->date($data['available_until'] ?? null);

        if ($from !== null && $until !== null && $until <= $from) {
            throw new InvalidArgumentException(__('plans.errors.window'));
        }

        $url = filled($data['cta_url'] ?? null) ? trim((string) $data['cta_url']) : null;

        if ($url !== null && preg_match('#^(https://|http://|mailto:|/)#i', $url) !== 1) {
            throw new InvalidArgumentException(__('plans.errors.cta_url'));
        }

        $attributes = [
            'name' => $name,
            'plan_type' => $type,
            'billing_frequency' => $frequency,
            'billing_interval_months' => $interval,
            'price_cents' => $price,
            'price_monthly_cents' => (int) round($price / $interval),
            'setup_fee_cents' => $setup,
            'currency' => $currency,
            'tax_mode' => $taxMode,
            'trial_days' => $trial,
            'internal_description' => filled($data['internal_description'] ?? null) ? trim((string) $data['internal_description']) : null,
            'public_description' => $this->texts($data['public_description'] ?? []),
            'features' => $this->features((array) ($data['features'] ?? [])),
            'limits' => $this->limits((array) ($data['limits'] ?? [])),
            'visibility' => $visibility,
            'invite_code' => $invite,
            'segments' => $visibility === 'segments' ? $segments : null,
            'cta_type' => $cta,
            'cta_url' => $url,
            'available_from' => $from,
            'available_until' => $until,
            'is_recommended' => (bool) ($data['is_recommended'] ?? false),
        ];

        if (isset($data['status'])) {
            $attributes['status'] = (string) $data['status'];

            if (! in_array($attributes['status'], Plan::STATUSES, true)) {
                throw new InvalidArgumentException(__('plans.errors.unknown_status'));
            }
        }

        if (isset($data['position']) && $data['position'] !== '') {
            $attributes['position'] = max(0, (int) $data['position']);
        }

        return $attributes;
    }

    /**
     * Publier exige une formule cohérente : un paiement unique n'est pas encaissable, une formule achetable a un prix et une période.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertPublishable(array $attributes, string $status): void
    {
        if ($status !== Plan::ACTIVE) {
            return;
        }

        $type = (string) $attributes['plan_type'];

        if ($type === 'one_time') {
            throw new DomainException(__('plans.errors.one_time_unsupported'));
        }

        if (in_array($type, Plan::BILLABLE_TYPES, true) && (int) $attributes['price_cents'] <= 0) {
            throw new DomainException(__('plans.errors.price_required'));
        }

        if (($attributes['cta_type'] ?? '') === 'contact_sales' && blank($attributes['cta_url'] ?? null)) {
            throw new DomainException(__('plans.errors.cta_url_required'));
        }

        if (($attributes['cta_type'] ?? 'choose_plan') === 'choose_plan' && ! in_array($type, Plan::BILLABLE_TYPES, true)) {
            throw new DomainException(__('plans.errors.cta_not_purchasable'));
        }
    }

    /** Crée la nouvelle version ; l'ancienne garde code historique, prix et limites pour ses abonnés. */
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fork(PlatformPlan $old, array $attributes, string $adminId): PlatformPlan
    {
        $id = (string) Str::uuid7();
        $oldCode = $old->code;
        $version = $old->version + 1;
        $status = $attributes['status'] ?? $old->status;

        // L'ancienne version libère son code, son code d'invitation et sa place « recommandée » avant que la nouvelle les reprenne.
        $old->forceFill([
            'code' => Str::limit($oldCode, 26, '').'~v'.$old->version,
            'status' => Plan::ARCHIVED,
            'superseded_by' => null,
            'invite_code' => null,
            'is_recommended' => false,
        ])->save();

        $new = PlatformPlan::query()->create($attributes + [
            'id' => $id,
            'family_id' => $old->family_id,
            'version' => $version,
            'code' => $oldCode,
            'position' => $old->position,
            'status' => $status,
        ]);

        $old->forceFill(['superseded_by' => $new->id])->save();
        PlatformAudit::record($adminId, 'plan.versioned', null, 'plans', $new->id, ['code' => $oldCode, 'version' => $version, 'previous' => $old->id, 'subscribers_kept' => $this->subscribers($old)]);

        return $new;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function isCommercialChange(PlatformPlan $plan, array $attributes): bool
    {
        foreach (self::COMMERCIAL as $key) {
            if (array_key_exists($key, $attributes) && $this->comparable($key, $plan->getAttribute($key)) !== $this->comparable($key, $attributes[$key])) {
                return true;
            }
        }

        return false;
    }

    /** Valeur ramenée à une forme comparable : les limites absentes valent « illimité », comme à la saisie. */
    private function comparable(string $key, mixed $value): string
    {
        if ($key === 'limits') {
            $value = array_merge(array_fill_keys(Plan::LIMIT_KEYS, null), (array) $value);
        }

        if (is_array($value)) {
            ksort($value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Valeurs actuelles d'une formule, au format du formulaire.
     *
     * @return array<string, mixed>
     */
    public function current(PlatformPlan $plan): array
    {
        return [
            'name' => $plan->name, 'code' => $plan->code, 'plan_type' => $plan->plan_type, 'billing_frequency' => $plan->billing_frequency,
            'billing_interval_months' => $plan->billing_interval_months, 'price_cents' => $plan->price_cents, 'setup_fee_cents' => $plan->setup_fee_cents,
            'currency' => trim($plan->currency), 'tax_mode' => $plan->tax_mode, 'trial_days' => $plan->trial_days,
            'internal_description' => $plan->internal_description, 'public_description' => $plan->public_description ?? [], 'features' => $plan->features,
            'limits' => $plan->limits, 'visibility' => $plan->visibility, 'invite_code' => $plan->invite_code, 'segments' => $plan->segments ?? [],
            'cta_type' => $plan->cta_type, 'cta_url' => $plan->cta_url, 'available_from' => $plan->available_from, 'available_until' => $plan->available_until,
            'is_recommended' => $plan->is_recommended, 'status' => $plan->status, 'position' => $plan->position,
        ];
    }

    /** Une seule formule recommandée (garanti aussi par la base) : en désigner une retire la précédente. */
    private function releaseRecommended(array $attributes, ?string $exceptId = null): void
    {
        if (($attributes['is_recommended'] ?? false) === true) {
            $this->db()->table('plans')->where('is_recommended', true)->when($exceptId !== null, fn ($query) => $query->where('id', '<>', $exceptId))->update(['is_recommended' => false]);
        }
    }

    private function newCode(string $seed): string
    {
        $base = Str::of(Str::slug($seed, '-'))->limit(24, '')->trim('-')->toString() ?: 'plan';
        $code = $base;
        $n = 2;

        while ($this->db()->table('plans')->where('code', $code)->exists()) {
            $code = Str::limit($base, 24 - strlen((string) $n), '').'-'.$n++;
        }

        return $code;
    }

    private function copyName(string $name): string
    {
        return Str::limit($name, 60 - strlen(' '.__('plans.copy_suffix')), '').' '.__('plans.copy_suffix');
    }

    private function cents(mixed $value, string $errorKey): int
    {
        if (! is_numeric($value) || (int) $value < 0 || (int) $value > 100_000_000) {
            throw new InvalidArgumentException(__($errorKey));
        }

        return (int) $value;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value)->utc();
    }

    /**
     * @param  array<string, mixed>  $texts
     * @return array<string, string>
     */
    private function texts(array $texts): array
    {
        $clean = [];

        foreach ((array) config('rendivoo.locales') as $language) {
            $text = trim((string) ($texts[$language] ?? ''));

            if ($text !== '') {
                $clean[(string) $language] = mb_substr($text, 0, 300);
            }
        }

        return $clean;
    }

    /**
     * Fonctionnalités dans l'ordre saisi : libellé par langue (le français est obligatoire), état inclus / limité / indisponible, valeur facultative.
     *
     * @param  array<int|string, mixed>  $features
     * @return list<array{label: array<string, string>, state: string, value: string|null}>
     */
    private function features(array $features): array
    {
        $clean = [];

        foreach (array_values($features) as $feature) {
            $feature = (array) $feature;
            $label = $this->texts((array) ($feature['label'] ?? []));
            $state = (string) ($feature['state'] ?? 'included');

            if ($label === [] && blank($feature['value'] ?? null)) {
                continue;
            }

            if (! isset($label[(string) config('app.fallback_locale')])) {
                throw new InvalidArgumentException(__('plans.errors.feature_label'));
            }

            if (! in_array($state, Plan::FEATURE_STATES, true)) {
                throw new InvalidArgumentException(__('plans.errors.feature_state'));
            }

            $clean[] = ['label' => $label, 'state' => $state, 'value' => filled($feature['value'] ?? null) ? mb_substr(trim((string) $feature['value']), 0, 80) : null];
        }

        if (count($clean) > 40) {
            throw new InvalidArgumentException(__('plans.errors.too_many_features'));
        }

        return $clean;
    }

    /**
     * Limites appliquées par la plateforme ; vide = illimité.
     *
     * @param  array<string, mixed>  $limits
     * @return array<string, int|null>
     */
    private function limits(array $limits): array
    {
        $clean = [];

        foreach (Plan::LIMIT_KEYS as $key) {
            $value = $limits[$key] ?? null;

            if ($value === null || $value === '') {
                $clean[$key] = null;

                continue;
            }

            if (! is_numeric($value) || (int) $value < 0 || (int) $value > 10_000_000) {
                throw new InvalidArgumentException(__('plans.errors.limit'));
            }

            $clean[$key] = (int) $value;
        }

        return $clean;
    }

    private function defaultCta(string $type): string
    {
        return match ($type) {
            'free_trial' => 'start_trial',
            'custom' => 'contact_sales',
            default => 'choose_plan',
        };
    }

    private function db(): Connection
    {
        return DB::connection(PlatformAudit::CONNECTION);
    }
}
