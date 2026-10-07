<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Console;

use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Support\TemplateCatalog;
use App\Modules\Whatsapp\Support\TransportFailed;
use Illuminate\Console\Command;

/**
 * Contrôle de bout en bout, contre la Graph API réelle (`WHATSAPP_DRIVER=meta`), d'un compte WhatsApp Business de TEST : valide le numéro
 * et le jeton, liste les modèles et indique lesquels de Nova existent. N'écrit RIEN en base et ne soumet rien à Meta.
 * Le jeton est saisi de façon masquée (jamais en argument : il finirait dans l'historique du terminal).
 */
final class CheckWhatsappAccount extends Command
{
    protected $signature = 'whatsapp:check {phone_number_id : Identifiant du numéro chez Meta} {waba_id : Identifiant du compte WhatsApp Business}';

    protected $description = 'Vérifie un compte WhatsApp Business de test auprès de Meta (aucune écriture)';

    public function handle(WhatsappTransport $transport): int
    {
        $token = (string) $this->secret('Jeton d\'accès Meta');

        if ($token === '') {
            $this->error('Jeton vide.');

            return self::FAILURE;
        }

        $account = new WhatsappAccount(['phone_number_id' => (string) $this->argument('phone_number_id'), 'waba_id' => (string) $this->argument('waba_id'), 'access_token_enc' => $token]);

        try {
            $number = $transport->verifyNumber($account);
            $this->info('Numéro valide : '.($number['display_phone'] ?? '?').' ('.($number['verified_name'] ?? 'nom non vérifié').')');

            $known = collect($transport->listTemplates($account))->mapWithKeys(fn ($info): array => [$info->name.'|'.$info->language => $info->status]);
        } catch (TransportFailed $e) {
            $this->error("Meta a refusé la requête ({$e->errorCode}).");

            return self::FAILURE;
        }

        $this->line('Modèles de Nova chez Meta :');

        foreach (TemplateCatalog::entries() as $entry) {
            $this->line(sprintf('  %-26s %-3s %s', $entry['name'], $entry['language'], $known[$entry['name'].'|'.$entry['language']] ?? 'absent'));
        }

        return self::SUCCESS;
    }
}
