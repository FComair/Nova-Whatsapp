<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Services;

use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Support\ConnectionRefused;
use App\Modules\Whatsapp\Support\TransportFailed;
use Illuminate\Support\Facades\DB;

/**
 * Connexion du numéro WhatsApp Business d'un établissement (étape 7 de l'onboarding, erreur E5).
 *
 * L'établissement fournit l'identifiant du numéro, l'identifiant du compte WhatsApp Business et un jeton d'accès Meta. Rien n'est
 * enregistré tant que Meta n'a pas confirmé que le numéro et le jeton sont valides. Le jeton est chiffré en base et jamais renvoyé.
 * À exécuter sous le contexte de l'organisation.
 *
 * Non fait : l'inscription intégrée de Meta (« Embedded Signup »), qui évite de manipuler un jeton, exige une application Meta de Rendivoo.
 */
final class WhatsappConnector
{
    public function __construct(private readonly WhatsappTransport $transport) {}

    public function current(): ?WhatsappAccount
    {
        return WhatsappAccount::query()->where('status', 'active')->orderBy('created_at')->first();
    }

    /**
     * @throws ConnectionRefused invalid_credentials, already_connected ou unavailable
     */
    public function connect(Organization $organization, string $phoneNumberId, string $wabaId, string $token, string $userId): WhatsappAccount
    {
        // Le numéro appartient-il déjà à un autre établissement ? (fonction étroite : seule l'organisation est renvoyée)
        $owner = DB::selectOne('select org_id from whatsapp_account_lookup(?)', [$phoneNumberId]);

        if ($owner !== null && (string) $owner->org_id !== $organization->id) {
            throw new ConnectionRefused('already_connected');
        }

        $candidate = new WhatsappAccount(['phone_number_id' => $phoneNumberId, 'waba_id' => $wabaId, 'access_token_enc' => $token]);

        try {
            $verified = $this->transport->verifyNumber($candidate);
        } catch (TransportFailed $e) {
            throw new ConnectionRefused($e->errorCode === 'network' ? 'unavailable' : 'invalid_credentials');
        }

        // Un seul numéro actif par établissement : le nouveau remplace l'ancien.
        WhatsappAccount::query()->where('phone_number_id', '!=', $phoneNumberId)->delete();

        $account = WhatsappAccount::query()->updateOrCreate(
            ['phone_number_id' => $phoneNumberId],
            ['org_id' => $organization->id, 'waba_id' => $wabaId, 'display_phone' => $verified['display_phone'], 'access_token_enc' => $token, 'status' => 'active'],
        );

        Audit::record('whatsapp.connected', 'user', $userId, $organization->id, 'whatsapp_accounts', $account->id, ['phone_number_id' => $phoneNumberId]);

        return $account;
    }

    /** Détache le numéro : le jeton est supprimé et les messages entrants ne sont plus reçus. Les conversations restent. */
    public function disconnect(Organization $organization, string $userId): void
    {
        $account = $this->current();

        if ($account === null) {
            return;
        }

        $account->delete();
        WhatsappTemplate::query()->delete();

        Audit::record('whatsapp.disconnected', 'user', $userId, $organization->id, 'whatsapp_accounts', $account->id);
    }
}
