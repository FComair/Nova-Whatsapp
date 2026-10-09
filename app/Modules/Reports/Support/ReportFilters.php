<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Période et filtres communs à tous les rapports. Les dates sont des jours du calendrier à l'heure du site (jamais celle du
 * navigateur ni du serveur) ; chaque rendez-vous est rattaché au jour local de son site.
 */
final readonly class ReportFilters
{
    public const PRESETS = ['today', 'this_week', 'this_month', 'last_month', 'this_quarter', 'custom'];

    public const DEFAULT_PRESET = 'this_month';

    /** Au-delà, la période personnalisée est ramenée à cette durée (graphiques lisibles, calcul borné). */
    public const MAX_DAYS = 366;

    /** Statuts proposés au filtre. « cancelled » regroupe annulation par le client et par l'établissement. */
    public const STATUSES = ['confirmed', 'completed', 'no_show', 'cancelled'];

    public const SOURCES = ['dashboard', 'whatsapp', 'staff_whatsapp', 'api'];

    /**
     * @param  list<string>  $statuses
     */
    public function __construct(
        public string $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?string $locationId,
        public ?string $practitionerId,
        public ?string $serviceId,
        public array $statuses,
        public ?string $source,
        public string $timezone,
    ) {}

    public static function fromRequest(Request $request, string $timezone, ?CarbonImmutable $now = null): self
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();
        $preset = in_array($request->query('preset'), self::PRESETS, true) ? (string) $request->query('preset') : self::DEFAULT_PRESET;

        [$from, $to] = $preset === 'custom' ? self::custom($request, $today) : self::range($preset, $today);

        $statuses = array_values(array_intersect(self::STATUSES, array_map('strval', (array) $request->query('status', []))));
        $source = $request->query('source');

        return new self(
            preset: $preset,
            from: $from,
            to: $to,
            locationId: self::uuid($request->query('location')),
            practitionerId: self::uuid($request->query('practitioner')),
            serviceId: self::uuid($request->query('service')),
            statuses: $statuses,
            source: in_array($source, self::SOURCES, true) ? (string) $source : null,
            timezone: $timezone,
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $preset, CarbonImmutable $today): array
    {
        return match ($preset) {
            'today' => [$today, $today],
            'this_week' => [$today->startOfWeek(), $today->startOfWeek()->addDays(6)],
            'last_month' => [$today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()->subDay()],
            'this_quarter' => [$today->startOfQuarter(), $today->endOfQuarter()->startOfDay()],
            default => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function custom(Request $request, CarbonImmutable $today): array
    {
        $from = self::date($request->query('from'), $today->startOfMonth());
        $to = self::date($request->query('to'), $today);

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $to = $from->addDays(self::MAX_DAYS - 1);
        }

        return [$from, $to];
    }

    private static function date(mixed $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                return CarbonImmutable::createFromFormat('!Y-m-d', $value, $fallback->timezone) ?: $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        }

        return $fallback;
    }

    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? strtolower($value) : null;
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * Période équivalente précédente : la même unité un cran plus tôt (semaine, mois, trimestre) ; pour « aujourd'hui » et une
     * période personnalisée, la période de même durée qui la précède immédiatement.
     */
    public function previous(): self
    {
        [$from, $to] = match ($this->preset) {
            'this_week' => [$this->from->subWeek(), $this->to->subWeek()],
            'this_month' => [$this->from->subMonthNoOverflow(), $this->from->subDay()],
            'last_month' => [$this->from->subMonthNoOverflow(), $this->from->subDay()],
            'this_quarter' => [$this->from->subMonthsNoOverflow(3), $this->from->subDay()],
            default => [$this->from->subDays($this->days()), $this->from->subDay()],
        };

        return $this->with(from: $from, to: $to);
    }

    public function with(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): self
    {
        return new self($this->preset, $from ?? $this->from, $to ?? $this->to, $this->locationId, $this->practitionerId, $this->serviceId, $this->statuses, $this->source, $this->timezone);
    }

    /** Période encore en cours : des jours à venir s'y trouvent, la comparaison avec une période entière est partielle. */
    public function inProgress(?CarbonImmutable $now = null): bool
    {
        return $this->to->greaterThan(($now ?? CarbonImmutable::now())->setTimezone($this->timezone)->startOfDay());
    }

    /**
     * Une borne basse et une borne haute en UTC, élargies d'un jour de chaque côté : préfiltre utilisable avec l'index.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcBounds(): array
    {
        return [$this->from->subDay()->utc(), $this->to->addDays(2)->utc()];
    }

    public function hasEntityFilter(): bool
    {
        return $this->locationId !== null || $this->practitionerId !== null || $this->serviceId !== null || $this->source !== null;
    }

    public function hasAnyFilter(): bool
    {
        return $this->hasEntityFilter() || $this->statuses !== [];
    }

    /**
     * Paramètres d'adresse qui reproduisent ces filtres (liens entre catégories, exports, impression).
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return array_filter([
            'preset' => $this->preset,
            'from' => $this->preset === 'custom' ? $this->from->toDateString() : null,
            'to' => $this->preset === 'custom' ? $this->to->toDateString() : null,
            'location' => $this->locationId,
            'practitioner' => $this->practitionerId,
            'service' => $this->serviceId,
            'status' => $this->statuses === [] ? null : $this->statuses,
            'source' => $this->source,
        ], fn ($value): bool => $value !== null);
    }
}
