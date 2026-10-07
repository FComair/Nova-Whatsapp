<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Http\Controllers;

use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Modules\Whatsapp\Services\EmbeddedSignup;
use App\Modules\Whatsapp\Services\TemplateManager;
use App\Modules\Whatsapp\Services\WhatsappConnector;
use App\Modules\Whatsapp\Support\ConnectionRefused;
use App\Modules\Whatsapp\Support\TransportFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres > WhatsApp (propriétaire) : connexion du numéro et modèles de messages (flowcharts 1B à 1D, erreurs E5 et E6).
 * Les mêmes actions servent l'assistant d'onboarding (étapes 7 et 8) : elles reviennent à la page d'où elles sont lancées.
 */
final class WhatsappController
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly WhatsappConnector $connector,
        private readonly TemplateManager $templates,
        private readonly EmbeddedSignup $embedded,
    ) {}

    public function show(): Response
    {
        return Inertia::render('Account/Whatsapp', $this->state());
    }

    /**
     * Données communes à la page de paramètres et aux étapes de l'assistant.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $account = $this->connector->current();

        return [
            'account' => $account === null ? null : ['phone_number_id' => $account->phone_number_id, 'waba_id' => $account->waba_id, 'display_phone' => $account->display_phone],
            'templates' => $account === null ? [] : $this->templates->overview(),
            'allApproved' => $account !== null && $this->templates->allApproved(),
            // Identifiants publics de l'application Meta, ou null : le bouton « Se connecter avec Meta » n'est proposé que s'il est configuré.
            'embedded' => $this->embedded->clientConfig(),
        ];
    }

    public function connect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'waba_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'access_token' => ['required', 'string', 'min:20', 'max:1000'],
            'password' => ['required', 'string'],
        ]);

        if (! $this->passwordOk($request)) {
            return back()->withErrors(['password' => __('ui.whatsapp.errors.password')]);
        }

        try {
            $this->connector->connect($this->organization(), $data['phone_number_id'], $data['waba_id'], $data['access_token'], (string) $request->user()?->id);
        } catch (ConnectionRefused $e) {
            return back()->withErrors(['whatsapp' => __("ui.whatsapp.errors.{$e->reason}")]);
        }

        return back()->with('status', __('ui.whatsapp.connected'));
    }

    /** Fin de l'inscription intégrée : le navigateur renvoie le code à usage unique de Meta et les identifiants du numéro et du compte. */
    public function embedded(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:2000'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'waba_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
        ]);

        try {
            $this->embedded->complete($this->organization(), $data['code'], $data['phone_number_id'], $data['waba_id'], (string) $request->user()?->id);
        } catch (ConnectionRefused $e) {
            return back()->withErrors(['whatsapp' => __("ui.whatsapp.errors.{$e->reason}")]);
        }

        return back()->with('status', __('ui.whatsapp.connected'));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! $this->passwordOk($request)) {
            return back()->withErrors(['password' => __('ui.whatsapp.errors.password')]);
        }

        $this->connector->disconnect($this->organization(), (string) $request->user()?->id);

        return back()->with('status', __('ui.whatsapp.disconnected'));
    }

    public function submitTemplates(Request $request): RedirectResponse
    {
        $account = $this->connector->current();

        if ($account === null) {
            return back()->withErrors(['whatsapp' => __('ui.whatsapp.errors.not_connected')]);
        }

        $result = $this->templates->submitMissing($this->organization(), $account, (string) $request->user()?->id);

        if ($result['failed'] > 0) {
            return back()->withErrors(['whatsapp' => __('ui.whatsapp.errors.templates_failed', ['count' => $result['failed']])]);
        }

        return back()->with('status', $result['submitted'] > 0 ? __('ui.whatsapp.templates_submitted', ['count' => $result['submitted']]) : __('ui.whatsapp.templates_nothing'));
    }

    public function syncTemplates(): RedirectResponse
    {
        $account = $this->connector->current();

        if ($account === null) {
            return back()->withErrors(['whatsapp' => __('ui.whatsapp.errors.not_connected')]);
        }

        try {
            $this->templates->sync($this->organization(), $account);
        } catch (TransportFailed) {
            return back()->withErrors(['whatsapp' => __('ui.whatsapp.errors.unavailable')]);
        }

        return back()->with('status', __('ui.whatsapp.templates_synced'));
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }

    private function passwordOk(Request $request): bool
    {
        $user = $request->user();
        $key = 'whatsapp-password:'.$user?->id;

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
}
