<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Journal d'audit des actions du Super Admin : écrit avec le rôle `nova_platform` (droit d'insertion, jamais de modification),
 * avec l'adresse IP et l'agent utilisateur (docs/02 §3.3).
 */
final class PlatformAudit
{
    public const CONNECTION = 'pgsql_platform';

    /**
     * Action automatique de la plateforme (tâche planifiée), sans administrateur.
     *
     * @param  array<string, mixed>|null  $diff
     */
    public static function system(string $action, ?string $orgId = null, ?string $entityType = null, ?string $entityId = null, ?array $diff = null): void
    {
        DB::connection(self::CONNECTION)->table('audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'org_id' => $orgId,
            'actor_type' => 'system',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'diff' => $diff === null ? null : json_encode($diff, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $diff
     */
    public static function record(string $adminId, string $action, ?string $orgId = null, ?string $entityType = null, ?string $entityId = null, ?array $diff = null, ?string $reason = null): void
    {
        DB::connection(self::CONNECTION)->table('audit_logs')->insert([
            'id' => (string) Str::uuid7(),
            'org_id' => $orgId,
            'actor_type' => 'platform_admin',
            'actor_id' => $adminId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'diff' => $diff === null ? null : json_encode($diff, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }
}
