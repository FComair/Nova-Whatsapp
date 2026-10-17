<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Services;

use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Support\ConnectionRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Inscription intégrée de Meta (« Embedded Signup », décision du fondateur) : l'établissement connecte son numéro dans une fenêtre Meta,
 * sans manipuler d'identifiant ni de jeton. Le navigateur renvoie à Rendivoo un code à usage unique et les identifiants du numéro et du compte ;
 * Rendivoo échange le code contre un jeton (avec le secret de l'application Meta, qui ne quitte jamais le serveur), enregistre le compte
 * comme pour une connexion manuelle (vérification auprès de Meta, jeton chiffré) puis abonne le compte aux webhooks de Rendivoo.
 *
 * Désactivé tant que l'application Meta n'est pas configurée (`META_APP_ID`, `META_EMBEDDED_SIGNUP_CONFIG_ID`, `WHATSAPP_APP_SECRET`).
 * Les appels réels ne sont pas testés contre Meta (docs/17) : seuls les contrats le sont, avec des réponses simulées.
 */
final class EmbeddedSignup
{
    public function __construct(private readonly WhatsappConnector $connector) {}

    /** Vrai quand l'application Meta de Rendivoo est configurée : le bouton « Se connecter avec Meta » n'apparaît qu'alors. */
    public function enabled(): bool
    {
        return filled(config('whatsapp.embedded_signup.app_id')) && filled(config('whatsapp.embedded_signup.config_id')) && filled(config('whatsapp.app_secret'));
    }

    /**
     * @return array{app_id: string, config_id: string, version: string}|null données publiques pour le navigateur, ou null si désactivé
     */
    public function clientConfig(): ?array
    {
        return $this->enabled() ? [
            'app_id' => (string) config('whatsapp.embedded_signup.app_id'),
            'config_id' => (string) config('whatsapp.embedded_signup.config_id'),
            'version' => (string) config('whatsapp.graph_version'),
        ] : null;
    }

    /**
     * @throws ConnectionRefused disabled, invalid_credentials, already_connected ou unavailable
     */
    public function complete(Organization $organization, string $code, string $phoneNumberId, string $wabaId, string $userId): WhatsappAccount
    {
        if (! $this->enabled()) {
            throw new ConnectionRefused('disabled');
        }

        $token = $this->exchange($code);
        $account = $this->connector->connect($organization, $phoneNumberId, $wabaId, $token, $userId);

        $this->subscribe($account);

        return $account;
    }

    /**
     * @throws ConnectionRefused
     */
    private function exchange(string $code): string
    {
        try {
            $response = Http::timeout(10)->acceptJson()->get($this->url('oauth/access_token'), [
                'client_id' => config('whatsapp.embedded_signup.app_id'),
                'client_secret' => config('whatsapp.app_secret'),
                'code' => $code,
            ]);
        } catch (ConnectionException) {
            throw new ConnectionRefused('unavailable');
        }

        $token = $response->json('access_token');

        // Code périmé, déjà utilisé ou refusé : seul le fait est connu, jamais le contenu de la réponse.
        return $response->successful() && is_string($token) && $token !== '' ? $token : throw new ConnectionRefused('invalid_credentials');
    }

    /** Abonne le compte aux notifications de Rendivoo (messages entrants, statuts des modèles). Un échec n'annule pas la connexion. */
    private function subscribe(WhatsappAccount $account): void
    {
        try {
            Http::withToken($account->access_token_enc)->timeout(10)->acceptJson()->post($this->url("{$account->waba_id}/subscribed_apps"));
        } catch (ConnectionException) {
            // Réessayé à la prochaine synchronisation : la connexion elle-même est valide.
        }
    }

    private function url(string $path): string
    {
        return rtrim((string) config('whatsapp.graph_url'), '/').'/'.config('whatsapp.graph_version').'/'.$path;
    }
}
