<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Pose l'organisation courante sur la connexion PostgreSQL (`app.current_org`), ce que la RLS exploite.
 *
 * Le contexte est au niveau de la session de connexion : il DOIT être vidé en fin de requête / de job
 * (voir SetTenantContext) pour ne jamais fuiter vers la requête suivante qui réutiliserait la connexion.
 */
final class TenantContext
{
    private const SETTING = 'app.current_org';

    public function set(string $orgId): void
    {
        if (! Str::isUuid($orgId)) {
            throw new InvalidArgumentException('Identifiant d\'organisation invalide.');
        }

        DB::select('select set_config(?, ?, false)', [self::SETTING, $orgId]);
    }

    public function clear(): void
    {
        DB::select('select set_config(?, ?, false)', [self::SETTING, '']);
    }

    public function current(): ?string
    {
        $value = DB::selectOne('select current_setting(?, true) as v', [self::SETTING])->v ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * Exécute un bloc dans le contexte d'une organisation, puis restaure le contexte précédent.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(string $orgId, callable $callback): mixed
    {
        $previous = $this->current();
        $this->set($orgId);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->clear() : $this->set($previous);
        }
    }
}
