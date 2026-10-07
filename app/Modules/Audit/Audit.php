<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Point d'entrée unique pour tracer une action sensible (docs/02 §3.3).
 */
final class Audit
{
    /**
     * @param  array<string, mixed>|null  $diff
     */
    public static function record(
        string $action,
        string $actorType,
        ?string $actorId = null,
        ?string $orgId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $diff = null,
        ?string $reason = null,
    ): void {
        AuditLog::query()->insert([
            'id' => (string) Str::uuid7(),
            'org_id' => $orgId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
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
