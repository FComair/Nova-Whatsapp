<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Audit;
use App\Modules\Catalog\Models\PriceListImport;
use App\Modules\Catalog\Models\Service;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Application d'un import vérifié au catalogue, et annulation.
 *
 * Création : un nouveau service avec les valeurs par défaut du catalogue (marges 0, capacité 1, réservable en ligne).
 * Mise à jour : le prix seulement (et la catégorie quand le service n'en a pas) ; durée, TVA, marges et capacité ne bougent jamais.
 * Chaque service touché est tracé par le journal d'audit du catalogue ; l'import garde l'avant / après pour pouvoir être annulé.
 */
final class PriceListImporter
{
    public function __construct(private readonly ServiceCatalog $catalog, private readonly PriceListAnalyzer $analyzer) {}

    /**
     * @param  list<array{key: string, action: string, name?: string, price_cents?: int, category?: string|null, duration_minutes?: int, vat_rate?: int|null, service_id?: string|null}>  $lines  lignes vérifiées par l'utilisateur
     * @return array{added: int, updated: int, ignored: int}
     *
     * @throws PriceListException not_open (import déjà terminé) ou failed (service introuvable)
     */
    public function apply(Organization $organization, PriceListImport $import, array $lines, ?string $actorId): array
    {
        if ($import->status !== 'analysed') {
            throw new PriceListException('not_open');
        }

        $created = [];
        $updated = [];
        $ignored = 0;
        $applied = [];

        DB::transaction(function () use ($organization, $lines, &$created, &$updated, &$ignored, &$applied): void {
            foreach ($lines as $line) {
                if ($line['action'] === 'ignore') {
                    $ignored++;
                    $applied[] = $line;

                    continue;
                }

                if ($line['action'] === 'new') {
                    $service = $this->catalog->create($organization, [
                        'name' => (string) $line['name'],
                        'category' => $line['category'] ?? null,
                        'description' => null,
                        'duration_minutes' => (int) $line['duration_minutes'],
                        'buffer_before_minutes' => 0,
                        'buffer_after_minutes' => 0,
                        'price_cents' => (int) $line['price_cents'],
                        'vat_rate' => (int) ($line['vat_rate'] ?? 21),
                        'capacity' => 1,
                        'bookable_online' => true,
                        'notes_required' => false,
                    ]);
                    $created[] = ['id' => $service->id, 'fingerprint' => $this->fingerprint($service->refresh())];
                    $applied[] = $line;

                    continue;
                }

                $service = Service::query()->find($line['service_id'] ?? '') ?? throw new PriceListException('failed');
                $before = $this->catalog->updatePrice($organization, $service, (int) $line['price_cents'], $line['category'] ?? null);
                $updated[] = ['id' => $service->id, 'name' => $service->name, 'new_price' => (int) $line['price_cents']] + $before;
                $applied[] = $line;
            }
        });

        $import->forceFill([
            'status' => 'imported',
            'added' => count($created),
            'updated' => count($updated),
            'ignored' => $ignored,
            'imported_at' => now(),
            'result' => ['created' => $created, 'updated' => $updated, 'lines' => $applied],
        ])->save();

        $this->analyzer->discardFiles($import);

        Audit::record('catalog.price_list.imported', 'user', $actorId, $organization->id, 'price_list_imports', $import->id, ['added' => count($created), 'updated' => count($updated), 'ignored' => $ignored, 'files' => $import->file_names]);

        return ['added' => count($created), 'updated' => count($updated), 'ignored' => $ignored];
    }

    /** Empreinte des champs modifiables d'un service : change dès qu'on le modifie. */
    private function fingerprint(Service $service): string
    {
        return md5((string) json_encode($service->only([
            'name', 'description', 'category_id', 'duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes',
            'price_cents', 'vat_rate', 'capacity', 'bookable_online', 'notes_required', 'is_active',
        ])));
    }

    /** Un import terminé peut être annulé pendant `pricelist.undo_days` jours. */
    public function canUndo(PriceListImport $import): bool
    {
        return $import->status === 'imported'
            && $import->imported_at !== null
            && $import->imported_at->greaterThan(now()->subDays((int) config('pricelist.undo_days')));
    }

    /**
     * Annule un import : les services ajoutés sont retirés et les prix remis comme avant, SAUF pour ce qui a servi ou changé depuis
     * (réservation, modification) : ces services sont gardés et listés.
     *
     * @return array{removed: int, restored: int, kept: list<string>} noms des services gardés
     *
     * @throws PriceListException not_open
     */
    public function undo(Organization $organization, PriceListImport $import, ?string $actorId): array
    {
        if (! $this->canUndo($import)) {
            throw new PriceListException('not_open');
        }

        $result = (array) $import->result;
        $removed = 0;
        $restored = 0;
        $kept = [];

        DB::transaction(function () use ($organization, $result, &$removed, &$restored, &$kept): void {
            foreach ((array) ($result['created'] ?? []) as $entry) {
                $service = Service::query()->find($entry['id'] ?? '');

                if ($service === null) {
                    continue;
                }

                // Modifié depuis l'import ? (empreinte des champs du service, plus fiable qu'une date à la seconde près)
                $untouched = $this->fingerprint($service) === ($entry['fingerprint'] ?? null);
                $booked = DB::table('bookings')->where('service_id', $service->id)->exists();

                if ($untouched && ! $booked) {
                    $this->catalog->delete($organization, $service);
                    $removed++;
                } else {
                    $kept[] = $service->name;
                }
            }

            foreach ((array) ($result['updated'] ?? []) as $change) {
                $service = Service::query()->find($change['id'] ?? '');

                if ($service === null) {
                    continue;
                }

                if ($service->price_cents === (int) $change['new_price']) {
                    $this->catalog->restorePrice($organization, $service, (int) $change['old_price'], (bool) ($change['category_set'] ?? false), $change['old_category_id'] ?? null);
                    $restored++;
                } else {
                    $kept[] = $service->name;
                }
            }
        });

        $import->forceFill(['status' => 'undone', 'undone_at' => now()])->save();
        Audit::record('catalog.price_list.undone', 'user', $actorId, $organization->id, 'price_list_imports', $import->id, ['removed' => $removed, 'restored' => $restored, 'kept' => count($kept)]);

        return ['removed' => $removed, 'restored' => $restored, 'kept' => $kept];
    }
}
