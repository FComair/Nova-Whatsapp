<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export CSV de clients (docs/31 §11). Les données sont rangées par catégories que l'administrateur choisit (et voit avant d'exporter) ;
 * le contact principal (e-mail, téléphone) est une donnée personnelle : catégorie à part, à cocher. Jamais de secret ni de jeton de paiement.
 * Chaque export est consigné : qui, combien de clients, quelles catégories, quels filtres.
 */
final class ClientExport
{
    public const GROUPS = ['identity', 'contact', 'subscription', 'usage', 'tags'];

    /** Limite d'un export : au-delà, affiner les filtres. */
    public const LIMIT = 10000;

    /**
     * Colonnes de chaque catégorie : en-tête => lecture d'une ligne de la liste.
     *
     * @return array<string, array<string, callable(PlatformOrganization): mixed>>
     */
    public static function columns(): array
    {
        return [
            'identity' => [
                'client_id' => fn (PlatformOrganization $o) => $o->id,
                'name' => fn (PlatformOrganization $o) => $o->name,
                'legal_name' => fn (PlatformOrganization $o) => $o->billing_name,
                'vat_number' => fn (PlatformOrganization $o) => $o->billing_vat_number,
                'sector' => fn (PlatformOrganization $o) => $o->sector,
                'country' => fn (PlatformOrganization $o) => $o->country,
                'timezone' => fn (PlatformOrganization $o) => $o->timezone,
                'language' => fn (PlatformOrganization $o) => $o->locale,
                'account_status' => fn (PlatformOrganization $o) => $o->status,
                'created_at' => fn (PlatformOrganization $o) => $o->created_at->toIso8601String(),
            ],
            'contact' => [
                'owner_email' => fn (PlatformOrganization $o) => $o->getAttribute('owner_email'),
                'owner_phone' => fn (PlatformOrganization $o) => $o->getAttribute('owner_phone'),
            ],
            'subscription' => [
                'plan' => fn (PlatformOrganization $o) => $o->getAttribute('plan_name'),
                'subscription_state' => fn (PlatformOrganization $o) => $o->getAttribute('subscription_state'),
                'renewal_or_trial_end' => fn (PlatformOrganization $o) => $o->getAttribute('renewal_at'),
                'mrr' => fn (PlatformOrganization $o) => number_format((int) $o->getAttribute('mrr_cents') / 100, 2, '.', ''),
                'currency' => fn (PlatformOrganization $o) => $o->getAttribute('plan_currency'),
                'subscription_id' => fn (PlatformOrganization $o) => $o->getAttribute('subscription_id'),
            ],
            'usage' => [
                'active_members' => fn (PlatformOrganization $o) => $o->getAttribute('members_count'),
                'last_activity' => fn (PlatformOrganization $o) => $o->getAttribute('last_activity_at'),
            ],
            'tags' => ['tags' => fn (PlatformOrganization $o) => str_replace(',', ' ', (string) $o->getAttribute('tag_list'))],
        ];
    }

    /**
     * Ce que l'export contiendrait : nombre de clients et catégories retenues (affiché avant de confirmer).
     *
     * @param  Builder<PlatformOrganization>  $query
     * @param  list<string>  $groups
     * @return array{count: int, capped: bool, groups: list<string>}
     */
    public static function preview(Builder $query, array $groups): array
    {
        $count = DB::connection(PlatformAudit::CONNECTION)->query()->fromSub((clone $query)->toBase(), 'c')->count();

        return ['count' => $count, 'capped' => $count > self::LIMIT, 'groups' => self::normalise($groups)];
    }

    /**
     * Génère le CSV et le consigne.
     *
     * @param  Builder<PlatformOrganization>  $query  la liste, déjà filtrée ou limitée à une sélection
     * @param  list<string>  $groups
     * @param  array<string, mixed>  $filters  filtres appliqués, pour la trace
     */
    public static function download(PlatformAdmin $admin, Builder $query, array $groups, array $filters = [], string $scope = 'filtered'): StreamedResponse
    {
        $groups = self::normalise($groups);
        $columns = array_merge(...array_map(fn (string $group): array => self::columns()[$group], $groups));
        /** @var Collection<int, PlatformOrganization> $rows */
        $rows = (clone $query)->limit(self::LIMIT)->get();

        PlatformAudit::record($admin->id, 'client.exported', null, 'organizations', null, ['count' => $rows->count(), 'scope' => $scope, 'groups' => $groups, 'filters' => $filters]);

        return response()->streamDownload(function () use ($rows, $columns): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_keys($columns), ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (callable $read): string => self::safe($read($row)), $columns), ',', '"', '');
            }

            fclose($out);
        }, 'clients-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  list<string>  $groups
     * @return list<string>
     */
    private static function normalise(array $groups): array
    {
        $kept = array_values(array_intersect(self::GROUPS, $groups));

        return $kept === [] ? ['identity'] : $kept;
    }

    /** Une cellule commençant par = + - @ serait lue comme une formule par un tableur : on la neutralise. */
    private static function safe(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
