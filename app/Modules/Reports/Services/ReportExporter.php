<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\ReportAccess;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\Xlsx;
use App\Modules\Tenancy\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Export des rapports en CSV ou Excel, d'après les filtres de l'écran et les droits de l'utilisateur : ce qui est refusé à
 * l'écran (montants, praticiens, détail) n'est ni calculé ni écrit. Le contenu se compose à la demande :
 * - summary : indicateurs clés avec leur comparaison ; breakdown : les tableaux derrière chaque graphique ;
 * - detail : un rendez-vous par ligne, sans donnée personnelle de client.
 * Le PDF passe par la version imprimable (voir ReportController::print).
 *
 * @phpstan-import-type Table from ReportTables
 */
final class ReportExporter
{
    public const CONTENT = ['summary', 'breakdown', 'detail'];

    public const FORMATS = ['csv', 'xlsx'];

    private const NAMES = [
        ReportAccess::APPOINTMENTS => 'Appointments',
        ReportAccess::CUSTOMERS => 'Customers',
        ReportAccess::FINANCIAL => 'Financial',
        ReportAccess::PRACTITIONERS => 'Practitioners',
    ];

    public function __construct(
        private readonly ReportAccess $access,
        private readonly ReportService $reports,
        private readonly ReportTables $tables,
        private readonly DetailRows $detail,
        private readonly CurrentTenant $tenant,
    ) {}

    /**
     * Tableaux d'un export (catégories autorisées uniquement), dans l'ordre : informations, puis chaque rapport.
     *
     * @param  list<string>  $categories
     * @param  list<string>  $content
     * @return list<Table>
     */
    public function tables(array $categories, array $content, bool $staff, ReportFilters $filters): array
    {
        $categories = array_values(array_filter(ReportAccess::CATEGORIES, fn (string $category): bool => in_array($category, $categories, true) && $this->access->canViewCategory($category)));
        $staff = $staff && $this->access->canCompareStaff();
        $tables = [$this->information($categories, $content, $filters)];
        $currency = 'EUR';

        foreach ($categories as $category) {
            $report = $this->reports->category($category, $filters) ?? [];
            $currency = (string) ($report['currency'] ?? $currency);

            foreach ($this->tables->for($category, $report, $filters, $staff, $currency) as $table) {
                $wanted = $table['section'] === 'summary' ? 'summary' : 'breakdown';

                if (in_array($wanted, $content, true)) {
                    $tables[] = $this->named($table, self::NAMES[$category]);
                }
            }
        }

        if (in_array('detail', $content, true) && array_intersect($categories, [ReportAccess::APPOINTMENTS, ReportAccess::FINANCIAL, ReportAccess::PRACTITIONERS]) !== []) {
            $money = $this->access->canSeeFinancial() && array_intersect($categories, [ReportAccess::FINANCIAL]) !== [];
            $tables[] = $this->detail->appointments($filters, $staff, $money, $currency);

            // Une ligne par bénéficiaire de pourboire : la répartition, appointment par appointment (praticiens : droit requis).
            if ($money && $staff) {
                $tables[] = $this->detail->tips($filters, $currency);
            }
        }

        return $tables;
    }

    /**
     * Nom de fichier : « Appointments_Report_2026-10-01_to_2026-10-31.csv ».
     *
     * @param  list<string>  $categories
     */
    public function filename(array $categories, ReportFilters $filters, string $extension): string
    {
        $allowed = array_values(array_filter($categories, fn (string $category): bool => $this->access->canViewCategory($category) && isset(self::NAMES[$category])));
        $label = count($allowed) === 1 ? self::NAMES[$allowed[0]].'_Report' : 'Reports';

        return "{$label}_{$filters->from->toDateString()}_to_{$filters->to->toDateString()}.{$extension}";
    }

    /**
     * @param  list<Table>  $tables
     */
    public function csv(array $tables): string
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM : Excel lit l'UTF-8 (accents) sans manipulation.
        $single = count($tables) === 1;

        foreach ($tables as $index => $table) {
            if (! $single) {
                if ($index > 0) {
                    fputcsv($handle, [], ';', '"', '');
                }

                fputcsv($handle, [$this->safe($table['title'])], ';', '"', '');
            }

            fputcsv($handle, array_map(fn (array $column): string => $this->safe($column['label']), $table['columns']), ';', '"', '');

            foreach ($table['rows'] as $row) {
                fputcsv($handle, array_map(fn ($value) => is_string($value) ? $this->safe($value) : $value, $row), ';', '"', '');
            }
        }

        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * @param  list<Table>  $tables
     */
    public function xlsx(array $tables): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rendivoo-report-');
        Xlsx::write(array_map(fn (array $table): array => ['name' => $table['sheet'] ?? $table['title']] + $table, $tables), $path);

        return $path;
    }

    /**
     * Un texte commençant par = + - @ (ou une tabulation) serait exécuté comme formule par le tableur : on le désamorce.
     */
    private function safe(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }

    /**
     * @param  Table  $table
     * @return Table
     */
    private function named(array $table, string $category): array
    {
        $table['title'] = $this->categoryLabel($category).' — '.$table['title'];
        $table['sheet'] = Str::limit($this->categoryLabel($category).' '.$table['title'], 28, '');

        return $table;
    }

    private function categoryLabel(string $category): string
    {
        return (string) __('ui.reports.category.'.strtolower($category));
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $content
     * @return Table
     */
    private function information(array $categories, array $content, ReportFilters $filters): array
    {
        $previous = $filters->previous();
        $rows = [
            [(string) __('ui.reports.info.business'), $this->tenant->organization() === null ? '' : $this->tenant->organization()->name],
            [(string) __('ui.reports.info.reports'), implode(', ', array_map(fn (string $category): string => $this->categoryLabel(self::NAMES[$category]), $categories))],
            [(string) __('ui.reports.info.period'), $this->range($filters)],
            [(string) __('ui.reports.info.comparison'), $this->range($previous)],
            [(string) __('ui.reports.info.filters'), $this->filterSummary($filters)],
            [(string) __('ui.reports.info.content'), implode(', ', array_map(fn (string $item): string => (string) __("ui.reports.export.content.{$item}"), $content))],
            [(string) __('ui.reports.info.timezone'), $filters->timezone],
            [(string) __('ui.reports.info.generated'), CarbonImmutable::now($filters->timezone)->format('d/m/Y H:i')],
            [(string) __('ui.reports.info.note'), (string) __('ui.reports.info.note_text')],
        ];

        return [
            'key' => 'information',
            'title' => (string) __('ui.reports.table.information'),
            'section' => 'summary',
            'columns' => [['label' => (string) __('ui.reports.col.item'), 'type' => 'text'], ['label' => (string) __('ui.reports.col.value'), 'type' => 'text']],
            'rows' => $rows,
        ];
    }

    private function range(ReportFilters $filters): string
    {
        return $filters->from->format('d/m/Y').' – '.$filters->to->format('d/m/Y');
    }

    private function filterSummary(ReportFilters $filters): string
    {
        $parts = [];

        if ($filters->locationId !== null) {
            $parts[] = __('ui.reports.filter.location').': '.DB::table('locations')->where('id', $filters->locationId)->value('name');
        }

        if ($filters->practitionerId !== null) {
            $parts[] = __('ui.reports.filter.practitioner').': '.DB::table('practitioners')->where('id', $filters->practitionerId)->value('name');
        }

        if ($filters->serviceId !== null) {
            $parts[] = __('ui.reports.filter.service').': '.DB::table('services')->where('id', $filters->serviceId)->value('name');
        }

        if ($filters->statuses !== []) {
            $parts[] = __('ui.reports.filter.status').': '.implode(', ', array_map(fn (string $status): string => (string) __("ui.reports.status.{$status}"), $filters->statuses));
        }

        if ($filters->source !== null) {
            $parts[] = __('ui.reports.filter.source').': '.__("ui.reports.source.{$filters->source}");
        }

        if ($filters->paymentStatus !== null) {
            $parts[] = __('ui.reports.filter.payment').': '.__("ui.planning.payment.{$filters->paymentStatus}");
        }

        return $parts === [] ? (string) __('ui.reports.filter.none') : implode(' · ', $parts);
    }
}
