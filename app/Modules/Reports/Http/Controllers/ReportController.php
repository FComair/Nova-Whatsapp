<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Reports\Support\ReportAccess;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Rapports et analyses : vue d'ensemble et quatre catégories (rendez-vous, clients, finances, praticiens), impression et export.
 * Les droits (docs/02 §1) s'appliquent de la même façon à l'écran, à l'impression et aux exports : ce qui est refusé n'est pas calculé.
 */
final class ReportController
{
    public function __construct(
        private readonly ReportAccess $access,
        private readonly ReportService $reports,
        private readonly ReportExporter $exporter,
        private readonly CurrentTenant $tenant,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        abort_unless($this->access->canView(), 403);

        $category = (string) $request->query('category', ReportAccess::OVERVIEW);

        if (! $this->access->canViewCategory($category) && $category !== ReportAccess::OVERVIEW) {
            return redirect()->route('reports.index', $this->reports->filters($request)->query());
        }

        $filters = $this->reports->filters($request);
        $category = $this->access->canViewCategory($category) ? $category : ReportAccess::OVERVIEW;

        return Inertia::render('Reports/Index', [
            'category' => $category,
            'filters' => $this->filterValues($filters),
            'period' => $this->period($filters),
            'options' => $this->reports->options(),
            'access' => $this->accessProps(),
            'locale' => $this->locale(),
            'report' => $category === ReportAccess::OVERVIEW ? $this->reports->overview($filters) : $this->reports->category($category, $filters),
        ]);
    }

    /** Version imprimable des rapports choisis : sans navigation ni boutons, en-tête, pied de page et tableaux paginés. */
    public function print(Request $request): Response
    {
        abort_unless($this->access->canView(), 403);

        $filters = $this->reports->filters($request);
        $categories = $this->categories($request, default: $this->access->categories());
        $organization = $this->tenant->organization();
        $reports = [];

        foreach ($categories as $category) {
            $reports[$category] = $this->reports->category($category, $filters);
        }

        return Inertia::render('Reports/Print', [
            'categories' => $categories,
            'filters' => $this->filterValues($filters),
            'period' => $this->period($filters),
            'options' => $this->reports->options(),
            'access' => $this->accessProps(),
            'locale' => $this->locale(),
            'business' => ['id' => $organization?->id, 'name' => $organization?->name, 'logo_version' => $organization?->logo_updated_at?->getTimestamp()],
            'generatedAt' => now($filters->timezone)->toIso8601String(),
            'auto' => $request->boolean('auto'),
            'reports' => $reports,
        ]);
    }

    /** Aperçu de l'export : ce qu'il contiendra (tableaux, lignes) et le nom du fichier, avant de le produire. */
    public function preview(Request $request): JsonResponse
    {
        abort_unless($this->access->canExport(), 403);

        $filters = $this->reports->filters($request);
        $format = $this->format($request);
        $categories = $this->categories($request);
        $tables = $this->exporter->tables($categories, $this->content($request), $request->boolean('staff'), $filters);

        return response()->json([
            'filename' => $this->exporter->filename($categories, $filters, $format),
            'tables' => array_map(fn (array $table): array => ['title' => $table['title'], 'rows' => count($table['rows'])], $tables),
        ]);
    }

    public function export(Request $request): HttpResponse|BinaryFileResponse
    {
        abort_unless($this->access->canExport(), 403);

        $filters = $this->reports->filters($request);
        $format = $this->format($request);
        $categories = $this->categories($request);
        abort_if($categories === [], 422);

        $tables = $this->exporter->tables($categories, $this->content($request), $request->boolean('staff'), $filters);
        $filename = $this->exporter->filename($categories, $filters, $format);

        Audit::record('reports.exported', 'user', (string) auth()->id(), $this->tenant->organization()?->id, 'report', null, ['format' => $format, 'reports' => $categories, 'period' => [$filters->from->toDateString(), $filters->to->toDateString()]]);

        if ($format === 'xlsx') {
            return response()->download($this->exporter->xlsx($tables), $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
        }

        return response($this->exporter->csv($tables), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Le propriétaire décide si les responsables de site voient les rapports financiers. */
    public function settings(Request $request): RedirectResponse
    {
        abort_unless($this->access->canConfigure(), 403);

        $data = $request->validate(['managers_financial' => ['required', 'boolean']]);
        $organization = $this->tenant->organization();
        abort_if($organization === null, 403);

        $organization->forceFill(['reports_financial_managers' => (bool) $data['managers_financial']])->save();
        Audit::record('reports.settings.updated', 'user', (string) auth()->id(), $organization->id, 'organization', $organization->id, ['managers_financial' => (bool) $data['managers_financial']]);

        return back()->with('status', __('ui.reports.settings.saved'));
    }

    private function locale(): string
    {
        $organization = $this->tenant->organization();

        return $organization === null ? 'fr' : $organization->locale;
    }

    /**
     * @return array<string, mixed>
     */
    private function filterValues(ReportFilters $filters): array
    {
        return [
            'preset' => $filters->preset,
            'from' => $filters->from->toDateString(),
            'to' => $filters->to->toDateString(),
            'location' => $filters->locationId,
            'practitioner' => $filters->practitionerId,
            'service' => $filters->serviceId,
            'status' => $filters->statuses,
            'source' => $filters->source,
            'payment' => $filters->paymentStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function period(ReportFilters $filters): array
    {
        $previous = $filters->previous();

        return [
            'from' => $filters->from->toDateString(),
            'to' => $filters->to->toDateString(),
            'days' => $filters->days(),
            'previous' => ['from' => $previous->from->toDateString(), 'to' => $previous->to->toDateString()],
            'in_progress' => $filters->inProgress(),
            'timezone' => $filters->timezone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accessProps(): array
    {
        return [
            'categories' => $this->access->categories(),
            'financial' => $this->access->canSeeFinancial(),
            'export' => $this->access->canExport(),
            'staff' => $this->access->canCompareStaff(),
            'own_only' => $this->access->isPractitioner(),
            'can_configure' => $this->access->canConfigure(),
            'managers_financial' => (bool) $this->tenant->organization()?->reports_financial_managers,
            'role' => $this->access->role(),
        ];
    }

    /**
     * @param  list<string>|null  $default
     * @return list<string>
     */
    private function categories(Request $request, ?array $default = null): array
    {
        $asked = array_map('strval', (array) $request->query('reports', $default ?? []));

        return array_values(array_filter(ReportAccess::CATEGORIES, fn (string $category): bool => in_array($category, $asked, true) && $this->access->canViewCategory($category)));
    }

    /**
     * @return list<string>
     */
    private function content(Request $request): array
    {
        $asked = array_map('strval', (array) $request->query('content', ReportExporter::CONTENT));
        $content = array_values(array_intersect(ReportExporter::CONTENT, $asked));

        return $content === [] ? ['summary'] : $content;
    }

    private function format(Request $request): string
    {
        return in_array($request->query('format'), ReportExporter::FORMATS, true) ? (string) $request->query('format') : 'csv';
    }
}
