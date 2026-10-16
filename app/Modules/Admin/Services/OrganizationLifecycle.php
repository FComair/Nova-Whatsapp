<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Notifications\AccountStatusNotification;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\StatusRestorer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Cycle de vie d'un compte client décidé par le Super Admin : suspension et levée, suppression programmée et annulation.
 *
 * Chaque transition : statut vérifié, écrite avec le rôle plateforme, tracée (motif, IP, agent), propriétaire prévenu par e-mail
 * (langue du propriétaire). À appeler APRÈS `SensitiveActionGuard::authorize()`. La suppression réelle des données (J+30) et la
 * suppression immédiate pour fraude relèvent du pipeline RGPD, qui n'est pas dans ce service.
 */
final class OrganizationLifecycle
{
    /** Statuts depuis lesquels on peut suspendre. */
    private const SUSPENDABLE = [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE, Organization::STATUS_PAUSED];

    /** Statuts depuis lesquels une suppression peut être programmée. */
    private const DELETABLE = [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE, Organization::STATUS_SUSPENDED, Organization::STATUS_PAUSED];

    public function __construct(private readonly PlatformSettings $settings) {}

    public function suspend(PlatformOrganization $org, string $adminId, ?string $reason): void
    {
        AdminAccess::authorize($adminId, 'clients.suspend');
        $this->assertFrom($org, self::SUSPENDABLE);

        $this->transition($org, $adminId, 'organization.suspended', $reason, [
            'status' => Organization::STATUS_SUSPENDED,
            'previous_status' => $org->status,
            'suspended_at' => now(),
            'suspension_reason' => $reason === null ? null : mb_substr($reason, 0, 255),
        ]);
    }

    public function lift(PlatformOrganization $org, string $adminId, ?string $reason): void
    {
        AdminAccess::authorize($adminId, 'clients.suspend');
        $this->assertFrom($org, [Organization::STATUS_SUSPENDED]);

        $this->transition($org, $adminId, 'organization.suspension_lifted', $reason, [
            'status' => StatusRestorer::after($org->previous_status, $org->trial_ends_at),
            'previous_status' => null,
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);
    }

    public function scheduleDeletion(PlatformOrganization $org, string $adminId, ?string $reason): void
    {
        AdminAccess::authorize($adminId, 'clients.delete');
        $this->assertFrom($org, self::DELETABLE);

        $this->transition($org, $adminId, 'organization.deletion_scheduled', $reason, [
            'status' => Organization::STATUS_PENDING_DELETION,
            'previous_status' => $org->status,
            'deletion_requested_at' => now(),
            'deletion_scheduled_for' => CarbonImmutable::now()->addDays($this->settings->integer('deletion_delay_days')),
        ]);
    }

    /**
     * Suppression IMMÉDIATE, réservée à la fraude ou à l'abus : l'échéance devient maintenant ; la tâche planifiée efface les données
     * dans les minutes qui suivent. Irréversible dès que l'effacement a commencé.
     */
    public function deleteNow(PlatformOrganization $org, string $adminId, ?string $reason): void
    {
        AdminAccess::authorize($adminId, 'clients.delete');
        $this->assertFrom($org, [...self::DELETABLE, Organization::STATUS_PENDING_DELETION]);

        $this->transition($org, $adminId, 'organization.deletion_immediate', $reason, [
            'status' => Organization::STATUS_PENDING_DELETION,
            'previous_status' => $org->status === Organization::STATUS_PENDING_DELETION ? $org->previous_status : $org->status,
            'deletion_requested_at' => now(),
            'deletion_scheduled_for' => now(),
        ]);
    }

    public function cancelDeletion(PlatformOrganization $org, string $adminId, ?string $reason): void
    {
        AdminAccess::authorize($adminId, 'clients.delete');
        $this->assertFrom($org, [Organization::STATUS_PENDING_DELETION]);

        if ($org->deletion_started_at !== null) {
            throw new InvalidArgumentException('L\'effacement a déjà commencé : la suppression ne peut plus être annulée.');
        }

        $this->transition($org, $adminId, 'organization.deletion_cancelled', $reason, [
            'status' => StatusRestorer::afterDeletion($org->previous_status, $org->trial_ends_at),
            'previous_status' => null,
            'deletion_requested_at' => null,
            'deletion_scheduled_for' => null,
        ]);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertFrom(PlatformOrganization $org, array $allowed): void
    {
        if (! in_array($org->status, $allowed, true)) {
            throw new InvalidArgumentException("Transition impossible depuis le statut {$org->status}.");
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function transition(PlatformOrganization $org, string $adminId, string $action, ?string $reason, array $changes): void
    {
        $from = $org->status;

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($org, $adminId, $action, $reason, $changes, $from): void {
            $org->forceFill($changes)->save();

            PlatformAudit::record($adminId, $action, $org->id, 'organizations', $org->id, ['status' => [$from, $org->status]], $reason);
        });

        $this->notifyOwner($org, $action, $reason);
    }

    private function notifyOwner(PlatformOrganization $org, string $action, ?string $reason): void
    {
        $owner = DB::connection(PlatformAudit::CONNECTION)->selectOne('select * from org_owner_contact(?)', [$org->id]);

        if ($owner === null) {
            return;
        }

        // Au mieux : l'action est déjà enregistrée et auditée ; un serveur d'e-mail absent ou en panne ne doit pas la faire échouer
        // (le Super Admin verrait « Erreur lors du chargement de la page » alors que l'état a bien changé). L'erreur est journalisée.
        try {
            Notification::route('mail', (string) $owner->email)->notify(new AccountStatusNotification(
                $action,
                $org->name,
                $action === 'organization.suspended' ? $reason : null,
                $org->deletion_scheduled_for,
                (string) $owner->locale,
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
