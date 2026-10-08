<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Privacy\Models\DeletionJob;
use App\Modules\Privacy\Models\DeletionRequest;
use App\Modules\Privacy\Notifications\DeletionCompletedNotification;
use App\Modules\Privacy\Notifications\DeletionFailedNotification;
use App\Modules\Privacy\Support\ErasureSteps;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Services\OrganizationLogo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Suppression réelle d'un compte à l'échéance (décision Q7 : J+30 ; immédiate seulement pour fraude ou abus).
 *
 * Déroulé : (1) le contact du propriétaire est mémorisé pour le seul message de confirmation ; (2) une tâche par table, dans l'ordre
 * de `ErasureSteps` ; (3) chaque tâche est réessayée (3 tentatives) puis le fondateur est alerté (E12) ; (4) quand tout est fait,
 * l'organisation est pseudonymisée, marquée « supprimée », la confirmation est envoyée et l'adresse du propriétaire oubliée.
 *
 * Toutes les opérations passent par le rôle plateforme et la fonction SQL `gdpr_purge_org` : aucun accès direct aux tables des clients.
 * Les étapes déjà faites sont conservées : un incident se reprend là où il s'est arrêté, sans rien refaire.
 */
final class DeletionPipeline
{
    public const MAX_ATTEMPTS = 3;

    private const CONNECTION = PlatformAudit::CONNECTION;

    /**
     * Traite les suppressions dues. Un verrou empêche deux exécutions simultanées.
     *
     * @return int nombre de comptes entièrement supprimés pendant ce passage
     */
    public function run(): int
    {
        $db = DB::connection(self::CONNECTION);

        if (! (bool) $db->selectOne('select pg_try_advisory_lock(?) as ok', [7_300_113])->ok) {
            return 0;
        }

        try {
            $this->startDue();

            $completed = 0;

            foreach (DeletionRequest::query()->whereNull('completed_at')->whereNotNull('started_at')->orderBy('scheduled_for')->get() as $request) {
                $completed += $this->advance($request) ? 1 : 0;
            }

            return $completed;
        } finally {
            $db->selectOne('select pg_advisory_unlock(?)', [7_300_113]);
        }
    }

    /** Ouvre le suivi des comptes en suppression dont l'échéance est atteinte. */
    private function startDue(): void
    {
        $due = PlatformOrganization::query()->where('status', Organization::STATUS_PENDING_DELETION)->where('deletion_scheduled_for', '<=', now())->get();

        foreach ($due as $organization) {
            $request = DeletionRequest::query()->find($organization->id);

            if ($request?->started_at !== null) {
                continue;
            }

            $owner = DB::connection(self::CONNECTION)->selectOne('select * from org_owner_contact(?)', [$organization->id]);

            DB::connection(self::CONNECTION)->transaction(function () use ($organization, $owner, $request): void {
                $request ??= new DeletionRequest(['org_id' => $organization->id]);
                $request->forceFill([
                    'kind' => $organization->deletion_requested_at !== null && $organization->deletion_scheduled_for <= $organization->deletion_requested_at->addMinute() ? 'immediate' : 'standard',
                    'owner_email_enc' => $owner === null ? null : (string) $owner->email,
                    'owner_locale' => $owner === null ? null : (string) $owner->locale,
                    'org_name_enc' => $organization->name,
                    'scheduled_for' => $organization->deletion_scheduled_for,
                    'started_at' => now(),
                ])->save();
                $organization->forceFill(['deletion_started_at' => now()])->save();

                foreach (ErasureSteps::ALL as $step) {
                    DeletionJob::query()->firstOrCreate(['org_id' => $organization->id, 'step' => $step]);
                }

                PlatformAudit::system('gdpr.deletion_started', $organization->id, 'organizations', $organization->id, ['kind' => $request->kind]);
            });
        }
    }

    /**
     * Exécute les tâches restantes d'une suppression, dans l'ordre. S'arrête à la première qui échoue.
     *
     * @return bool vrai quand le compte est entièrement supprimé
     */
    private function advance(DeletionRequest $request): bool
    {
        $jobs = DeletionJob::query()->where('org_id', $request->org_id)->get()->keyBy('step');

        foreach (ErasureSteps::ALL as $step) {
            $job = $jobs[$step] ?? DeletionJob::query()->firstOrCreate(['org_id' => $request->org_id, 'step' => $step]);

            if ($job->status === 'done') {
                continue;
            }

            if ($job->attempts >= self::MAX_ATTEMPTS) {
                return false; // en attente d'une relance manuelle (Super Admin)
            }

            if (! $this->execute($request, $job)) {
                return false;
            }
        }

        $this->finalize($request);

        return true;
    }

    private function execute(DeletionRequest $request, DeletionJob $job): bool
    {
        $job->forceFill(['status' => 'running', 'attempts' => $job->attempts + 1])->save();

        try {
            $rows = (int) DB::connection(self::CONNECTION)->selectOne('select gdpr_purge_org(?, ?) as n', [$request->org_id, $job->step])->n;
        } catch (Throwable $e) {
            // Seul un code est conservé : jamais le message SQL, qui pourrait citer des valeurs.
            $job->forceFill(['status' => 'failed', 'last_error' => 'sql_'.($e->getCode() ?: 'error')])->save();
            Log::error('Effacement RGPD en échec', ['org' => $request->org_id, 'step' => $job->step, 'attempts' => $job->attempts]);

            if ($job->attempts >= self::MAX_ATTEMPTS) {
                $this->alert($request, $job);
            }

            return false;
        }

        $job->forceFill(['status' => 'done', 'rows_deleted' => $rows, 'last_error' => null, 'completed_at' => now()])->save();

        return true;
    }

    /** E12 : le fondateur est prévenu une seule fois par suppression bloquée. */
    private function alert(DeletionRequest $request, DeletionJob $job): void
    {
        PlatformAudit::system('gdpr.job_failed', $request->org_id, 'organizations', $request->org_id, ['step' => $job->step, 'error' => $job->last_error]);

        if ($request->alerted_at !== null) {
            return;
        }

        $request->forceFill(['alerted_at' => now()])->save();
        $founder = config('nova.founder_email');

        if (is_string($founder) && $founder !== '') {
            Notification::route('mail', $founder)->notify(new DeletionFailedNotification($request->org_id, $job->step, (string) $job->last_error));
        } else {
            Log::critical('Suppression RGPD bloquée et aucune adresse de fondateur configurée (NOVA_FOUNDER_EMAIL)', ['org' => $request->org_id, 'step' => $job->step]);
        }
    }

    private function finalize(DeletionRequest $request): void
    {
        $organization = PlatformOrganization::query()->findOrFail($request->org_id);
        $name = (string) $request->org_name_enc;

        DB::connection(self::CONNECTION)->transaction(function () use ($organization, $request): void {
            // Pseudonymisation : la ligne reste (identifiant, dates, secteur, pays) pour l'historique et les obligations comptables.
            $organization->forceFill([
                'name' => 'Organisation supprimée',
                'slug' => 'supprimee-'.$organization->id,
                'terminology' => null,
                'unavailable_message' => null,
                'billing_name' => null,
                'billing_vat_number' => null,
                'billing_address' => null,
                'logo_updated_at' => null,
                'suspension_reason' => null,
                'previous_status' => null,
                'status' => Organization::STATUS_DELETED,
                'deleted_at' => now(),
            ])->save();

            PlatformAudit::system('organization.deleted', $organization->id, 'organizations', $organization->id, ['kind' => $request->kind]);
        });

        // Le logo est une donnée de l'établissement : ses fichiers disparaissent avec le compte.
        app(OrganizationLogo::class)->purge($organization->id);
        // Les photos d'une liste de prix (import non terminé) sont aussi des données de l'établissement.
        Storage::disk('local')->deleteDirectory("pricelists/{$organization->id}");

        $email = $request->owner_email_enc;

        if (is_string($email) && $email !== '') {
            Notification::route('mail', $email)->notify(new DeletionCompletedNotification($name, (string) $request->owner_locale));
        }

        // L'adresse et le nom ne servaient qu'à cette confirmation.
        $request->forceFill(['owner_email_enc' => null, 'org_name_enc' => null, 'owner_locale' => null, 'completed_at' => CarbonImmutable::now()])->save();
    }

    /**
     * Relance manuelle (Super Admin) : les tâches échouées repartent à zéro tentative ; les tâches faites ne sont pas refaites.
     *
     * @return int nombre de tâches remises en file
     */
    public function retry(DeletionRequest $request): int
    {
        $request->forceFill(['alerted_at' => null])->save();

        return DeletionJob::query()->where('org_id', $request->org_id)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'last_error' => null]);
    }
}
