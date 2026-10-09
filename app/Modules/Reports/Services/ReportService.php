<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\ReportAccess;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée des rapports : filtres de la requête, rapport d'une catégorie (toujours d'après les droits de l'utilisateur),
 * vue d'ensemble et listes de choix des filtres. Un chiffre refusé par les droits n'est jamais calculé, donc jamais envoyé.
 */
final class ReportService
{
    public function __construct(
        private readonly ReportAccess $access,
        private readonly CurrentTenant $tenant,
        private readonly AppointmentsReport $appointments,
        private readonly CustomersReport $customers,
        private readonly FinancialReport $financial,
        private readonly PractitionersReport $practitioners,
        private readonly ReportInsights $insights,
    ) {}

    public function filters(Request $request): ReportFilters
    {
        return ReportFilters::fromRequest($request, $this->timezone($request));
    }

    /** Fuseau de référence de la période : celui du site choisi, sinon celui de l'établissement. */
    private function timezone(Request $request): string
    {
        $location = $request->query('location');

        if (is_string($location) && $location !== '') {
            $found = DB::table('locations')->where('id', $location)->value('timezone');

            if (is_string($found)) {
                return $found;
            }
        }

        $organization = $this->tenant->organization();

        return $organization === null ? 'UTC' : $organization->timezone;
    }

    /**
     * Rapport d'une catégorie, avec ses constats ; null si l'utilisateur n'y a pas droit.
     *
     * @return array<string, mixed>|null
     */
    public function category(string $category, ReportFilters $filters): ?array
    {
        if (! $this->access->canViewCategory($category) || $category === ReportAccess::OVERVIEW) {
            return null;
        }

        $values = $this->access->canSeeFinancial();

        return match ($category) {
            ReportAccess::APPOINTMENTS => $this->withInsights($this->appointments->build($filters, $values), $this->insights->appointments(...)),
            ReportAccess::CUSTOMERS => $this->withInsights($this->customers->build($filters), $this->insights->customers(...)),
            ReportAccess::FINANCIAL => $this->withInsights($this->financial->build($filters), $this->insights->financial(...)),
            default => $this->withInsights($this->practitioners->build($filters, $values), $this->insights->practitioners(...)),
        };
    }

    /**
     * Vue d'ensemble : les chiffres clés de chaque catégorie permise et la courbe des rendez-vous.
     *
     * @return array<string, mixed>
     */
    public function overview(ReportFilters $filters): array
    {
        $appointments = $this->category(ReportAccess::APPOINTMENTS, $filters);
        $customers = $this->category(ReportAccess::CUSTOMERS, $filters);
        $financial = $this->category(ReportAccess::FINANCIAL, $filters);
        $practitioners = $this->category(ReportAccess::PRACTITIONERS, $filters);

        return [
            'appointments' => $appointments === null ? null : ['metrics' => $appointments['metrics'], 'series' => $appointments['series'], 'insights' => $appointments['insights']],
            'customers' => $customers === null ? null : ['metrics' => $customers['metrics']],
            'financial' => $financial === null ? null : ['metrics' => $financial['metrics'], 'currency' => $financial['currency'], 'series' => $financial['series']],
            'practitioners' => $practitioners === null ? null : ['metrics' => $practitioners['metrics']],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  callable(array<string, mixed>): list<array{key: string, params: array<string, int|float|string>}>  $insights
     * @return array<string, mixed>
     */
    private function withInsights(array $report, callable $insights): array
    {
        $report['insights'] = $insights($report);

        return $report;
    }

    /**
     * Listes de choix des filtres, limitées à ce que l'utilisateur voit.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $own = $this->access->practitionerId();

        return [
            'locations' => $this->access->visibleLocations(),
            'practitioners' => DB::table('practitioners')->whereNull('deleted_at')->when($own !== null, fn ($q) => $q->where('id', $own))->orderBy('name')->get(['id', 'name'])
                ->map(fn (object $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name])->all(),
            'services' => DB::table('services')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (object $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name])->all(),
            'statuses' => ReportFilters::STATUSES,
            'sources' => ReportFilters::SOURCES,
            'payment_statuses' => ReportFilters::PAYMENT_STATUSES,
            'presets' => ReportFilters::PRESETS,
        ];
    }
}
