<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Controllers;

use App\Modules\Admin\Notifications\AccountStatusNotification;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Audit\Audit;
use App\Modules\Privacy\Notifications\ConfirmDeletionNotification;
use App\Modules\Privacy\Services\OrganizationExporter;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Modules\Tenancy\Support\StatusRestorer;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Mes données » (propriétaire uniquement, docs/02 §1) : export de toutes les données et demande de suppression du compte.
 * Ces écrans restent accessibles quand le compte est suspendu, en pause ou en suppression (docs/02 §4).
 * Toute action exige le mot de passe actuel ; la suppression exige aussi le nom exact de l'établissement.
 */
final class AccountDataController
{
    /** Statuts depuis lesquels le propriétaire peut demander la suppression. */
    private const CAN_REQUEST = [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE, Organization::STATUS_SUSPENDED, Organization::STATUS_PAUSED];

    public function __construct(private readonly CurrentTenant $current, private readonly PlatformSettings $settings) {}

    public function show(): Response
    {
        $organization = $this->organization();

        return Inertia::render('Account/Data', [
            'organizationName' => $organization->name,
            'status' => $organization->status,
            'canRequestDeletion' => in_array($organization->status, self::CAN_REQUEST, true),
            'deletionScheduledFor' => $organization->deletion_scheduled_for?->toIso8601String(),
            'canCancelDeletion' => $organization->status === Organization::STATUS_PENDING_DELETION && $organization->deletion_started_at === null,
            'deletionStarted' => $organization->deletion_started_at !== null,
            'deletionDelayDays' => $this->settings->integer('deletion_delay_days'),
            'csrf' => csrf_token(),
        ]);
    }

    /** Export JSON de toutes les données, en téléchargement. Au plus 3 par heure et par utilisateur. */
    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        $organization = $this->organization();
        abort_if($organization->status === Organization::STATUS_DELETED, 403);

        $request->validate(['password' => ['required', 'string']]);

        if (! $this->passwordOk($request)) {
            return back()->withErrors(['password' => __('ui.account_data.errors.password')]);
        }

        $key = 'account-export:'.$request->user()?->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors(['password' => __('ui.account_data.errors.export_limit')]);
        }

        RateLimiter::hit($key, 3600);
        Audit::record('organization.exported', 'user', (string) $request->user()?->id, $organization->id, 'organizations', $organization->id);

        // Le flux est écrit APRÈS la fin de la requête, donc hors du contexte d'organisation : on le repose (la RLS reste la barrière).
        return response()->streamDownload(function () use ($organization): void {
            app(TenantContext::class)->run($organization->id, function () use ($organization): void {
                foreach (app(OrganizationExporter::class)->stream($organization) as $chunk) {
                    echo $chunk;
                }
            });
        }, 'rendivoo-export-'.now()->format('Ymd').'.json', ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public function requestDeletion(Request $request): RedirectResponse
    {
        $organization = $this->organization();

        abort_unless(in_array($organization->status, self::CAN_REQUEST, true), 403);

        $request->validate(['password' => ['required', 'string'], 'confirm_word' => ['required', 'string'], 'confirm' => ['accepted']]);

        if (! $this->passwordOk($request)) {
            return back()->withErrors(['password' => __('ui.account_data.errors.password')]);
        }

        if (trim($request->string('confirm_word')->toString()) !== __('ui.account_data.confirm_word')) {
            return back()->withErrors(['confirm_word' => __('ui.account_data.errors.word')]);
        }

        // Étape 1 sur 2 : un lien de confirmation (24 h, usage unique) est envoyé à l'adresse du propriétaire ; rien n'est encore programmé.
        $token = Str::random(64);
        $organization->forceFill(['deletion_confirm_token_hash' => hash('sha256', $token), 'deletion_confirm_expires_at' => now()->addHours((int) config('rendivoo.security.email_link_ttl_hours'))])->save();

        $user = $request->user();
        Notification::route('mail', (string) $user?->email)->notify(new ConfirmDeletionNotification($token, $organization->name, (string) $user?->locale));
        Audit::record('organization.deletion_confirmation_sent', 'user', (string) $user?->id, $organization->id, 'organizations', $organization->id);

        return back()->with('status', __('ui.account_data.deletion_requested'));
    }

    /** Étape 2 sur 2 : le lien reçu par e-mail programme la suppression (propriétaire connecté, lien signé et non utilisé). */
    public function confirmDeletion(Request $request, string $token): RedirectResponse
    {
        $organization = $this->organization();

        abort_unless(in_array($organization->status, self::CAN_REQUEST, true), 403);

        if ($organization->deletion_confirm_token_hash === null || $organization->deletion_confirm_expires_at === null || $organization->deletion_confirm_expires_at->isPast()
            || ! hash_equals($organization->deletion_confirm_token_hash, hash('sha256', $token))) {
            return redirect()->route('account.data')->withErrors(['deletion' => __('ui.account_data.errors.link')]);
        }

        $scheduled = CarbonImmutable::now()->addDays($this->settings->integer('deletion_delay_days'));

        $organization->forceFill([
            'previous_status' => $organization->status,
            'status' => Organization::STATUS_PENDING_DELETION,
            'deletion_requested_at' => now(),
            'deletion_scheduled_for' => $scheduled,
            'deletion_confirm_token_hash' => null,
            'deletion_confirm_expires_at' => null,
        ])->save();

        Audit::record('organization.deletion_requested', 'user', (string) $request->user()?->id, $organization->id, 'organizations', $organization->id, ['scheduled_for' => $scheduled->toDateString()]);
        $this->notify($request, 'organization.deletion_scheduled', $organization, $scheduled);

        return redirect()->route('account.data')->with('status', __('ui.account_data.deletion_confirmed'));
    }

    /** Annule la suppression tant que l'effacement n'a pas commencé. */
    public function cancelDeletion(Request $request): RedirectResponse
    {
        $organization = $this->organization();

        abort_unless($organization->status === Organization::STATUS_PENDING_DELETION, 403);

        if ($organization->deletion_started_at !== null) {
            return back()->withErrors(['deletion' => __('ui.account_data.errors.started')]);
        }

        $organization->forceFill([
            'status' => StatusRestorer::afterDeletion($organization->previous_status, $organization->trial_ends_at),
            'previous_status' => null,
            'deletion_requested_at' => null,
            'deletion_scheduled_for' => null,
        ])->save();

        Audit::record('organization.deletion_cancelled', 'user', (string) $request->user()?->id, $organization->id, 'organizations', $organization->id);
        $this->notify($request, 'organization.deletion_cancelled', $organization, null);

        return back()->with('status', __('ui.account_data.deletion_cancelled'));
    }

    private function passwordOk(Request $request): bool
    {
        $user = $request->user();
        $key = 'account-data-password:'.$user?->id;

        if ($user === null || RateLimiter::tooManyAttempts($key, 5)) {
            return false;
        }

        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($key, 900);

            return false;
        }

        RateLimiter::clear($key);

        return true;
    }

    private function notify(Request $request, string $action, Organization $organization, ?CarbonImmutable $date): void
    {
        $user = $request->user();

        if ($user !== null) {
            Notification::route('mail', $user->email)->notify(new AccountStatusNotification($action, $organization->name, null, $date, $user->locale));
        }
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }
}
