<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\PriceListApplyRequest;
use App\Modules\Catalog\Http\Requests\PriceListFilesRequest;
use App\Modules\Catalog\Models\PriceListImport;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Catalog\Services\PriceListAnalyzer;
use App\Modules\Catalog\Services\PriceListException;
use App\Modules\Catalog\Services\PriceListImporter;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Import du catalogue depuis une liste de prix photographiée. Propriétaire uniquement (imposé par les routes).
 *
 * Parcours : envoi des photos (lecture immédiate) → page de vérification → confirmation (rien n'est écrit avant) → historique et annulation.
 * L'isolation entre établissements est assurée par la RLS : l'import d'un autre établissement est introuvable.
 */
final class PriceListImportController
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly PriceListAnalyzer $analyzer,
        private readonly PriceListImporter $importer,
    ) {}

    public function index(): Response
    {
        $imports = PriceListImport::query()->whereIn('status', ['imported', 'undone'])->latest()->limit(10)->get();
        $open = PriceListImport::query()->where('status', 'analysed')->latest()->first();

        return Inertia::render('Catalog/PriceListImport', [
            'available' => $this->analyzer->available(),
            'limits' => [
                'maxFiles' => (int) config('pricelist.max_files'),
                'maxMb' => intdiv((int) config('pricelist.max_file_kb'), 1024),
                'minSide' => (int) config('pricelist.min_side_px'),
                'perHour' => (int) config('pricelist.per_hour'),
            ],
            'open' => $open === null ? null : ['id' => $open->id, 'file_names' => $open->file_names, 'lines' => count($open->lines)],
            'history' => $imports->map(fn (PriceListImport $import): array => [
                'id' => $import->id,
                'status' => $import->status,
                'file_names' => $import->file_names,
                'added' => $import->added,
                'updated' => $import->updated,
                'ignored' => $import->ignored,
                'imported_at' => $import->imported_at?->toIso8601String(),
                'can_undo' => $this->importer->canUndo($import),
            ])->all(),
        ]);
    }

    public function store(PriceListFilesRequest $request): RedirectResponse
    {
        $organization = $this->organization();
        $key = 'pricelist:'.$organization->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('pricelist.per_hour'))) {
            return back()->withErrors(['files' => __('ui.price_import.errors.rate', ['max' => (int) config('pricelist.per_hour')])]);
        }

        RateLimiter::hit($key, 3600);

        /** @var list<UploadedFile> $files */
        $files = array_values((array) $request->file('files'));

        try {
            $import = $this->analyzer->analyse($organization, (string) $request->user()?->id, $files);
        } catch (PriceListException $e) {
            return back()->withErrors(['files' => __('ui.price_import.errors.'.$e->reason, ['page' => $e->page ?? 0]), 'reason' => $e->reason]);
        }

        return to_route('services.import.review', $import->id);
    }

    public function review(string $import): Response|RedirectResponse
    {
        $record = $this->find($import);

        if ($record->status !== 'analysed') {
            return to_route('services.import.index');
        }

        return Inertia::render('Catalog/PriceListReview', [
            'importRecord' => ['id' => $record->id, 'file_names' => $record->file_names],
            'lines' => $record->lines,
            'services' => Service::query()->orderBy('position')->get(['id', 'name', 'price_cents', 'category_id'])->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'price_cents' => $service->price_cents,
                'has_category' => $service->category_id !== null,
            ])->all(),
            'categories' => ServiceCategory::query()->orderBy('position')->pluck('name')->all(),
            'vatRates' => [0, 6, 12, 21],
        ]);
    }

    public function apply(PriceListApplyRequest $request, string $import): RedirectResponse
    {
        $record = $this->find($import);

        try {
            $result = $this->importer->apply($this->organization(), $record, $request->validated()['lines'], (string) $request->user()?->id);
        } catch (PriceListException $e) {
            return back()->withErrors(['lines' => __('ui.price_import.errors.'.($e->reason === 'not_open' ? 'not_open' : 'import_failed'))]);
        }

        $redirect = to_route('services.import.index')->with('status', __('ui.price_import.done', ['added' => $result['added'], 'updated' => $result['updated']]));

        // Un service que personne ne propose ne peut pas être réservé (le serveur exige le lien praticien-service) : on le dit tout de suite.
        $createdIds = array_column((array) ($record->refresh()->result['created'] ?? []), 'id');
        $unassigned = $createdIds === [] ? 0 : Service::query()->whereIn('id', $createdIds)->whereNotExists(
            fn ($query) => $query->selectRaw('1')->from('practitioner_services')->whereColumn('practitioner_services.service_id', 'services.id'),
        )->count();

        return $unassigned > 0 ? $redirect->with('warning', __('ui.price_import.unassigned', ['count' => $unassigned])) : $redirect;
    }

    public function undo(Request $request, string $import): RedirectResponse
    {
        try {
            $result = $this->importer->undo($this->organization(), $this->find($import), (string) $request->user()?->id);
        } catch (PriceListException) {
            return back()->withErrors(['import' => __('ui.price_import.errors.not_open')]);
        }

        $message = __('ui.price_import.undone', ['removed' => $result['removed'], 'restored' => $result['restored']]);

        if ($result['kept'] !== []) {
            $message .= ' '.__('ui.price_import.undone_kept', ['names' => implode(', ', $result['kept'])]);
        }

        return to_route('services.import.index')->with('status', $message);
    }

    /** Abandon d'un import non terminé : les photos sont effacées. */
    public function cancel(string $import): RedirectResponse
    {
        $record = $this->find($import);

        if ($record->status === 'analysed') {
            $record->forceFill(['status' => 'cancelled'])->save();
            $this->analyzer->discardFiles($record);
        }

        return to_route('services.import.index');
    }

    /** Lignes lues (telles qu'importées, ou proposées si l'import n'est pas terminé), pour les conserver dans un tableur. */
    public function csv(string $import): HttpResponse
    {
        $record = $this->find($import);
        $lines = (array) ($record->result['lines'] ?? $record->lines);

        $out = "\u{FEFF}".implode(';', [__('ui.price_import.csv.name'), __('ui.price_import.csv.category'), __('ui.price_import.csv.duration'), __('ui.price_import.csv.price'), __('ui.price_import.csv.vat'), __('ui.price_import.csv.action')])."\r\n";

        foreach ($lines as $line) {
            $cells = [
                (string) ($line['name'] ?? ''),
                (string) ($line['category'] ?? ''),
                (string) ($line['duration_minutes'] ?? ''),
                isset($line['price_cents']) ? number_format(((int) $line['price_cents']) / 100, 2, ',', '') : '',
                (string) ($line['vat_rate'] ?? ''),
                __('ui.price_import.actions.'.(string) ($line['action'] ?? 'new')),
            ];
            // Les cellules qui commencent par = + - @ seraient interprétées comme des formules par un tableur.
            $out .= implode(';', array_map(static fn (string $cell): string => '"'.str_replace('"', '""', preg_match('/^[=+\-@]/', $cell) ? "'".$cell : $cell).'"', $cells))."\r\n";
        }

        return response($out, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="price-list-'.Str::slug($record->imported_at?->format('Y-m-d') ?? 'draft').'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function find(string $id): PriceListImport
    {
        abort_unless(Str::isUuid($id), 404);

        return PriceListImport::query()->findOrFail($id);
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }
}
