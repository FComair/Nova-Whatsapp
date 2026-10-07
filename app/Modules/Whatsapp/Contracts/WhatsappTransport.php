<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Contracts;

use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Support\TemplateInfo;
use App\Modules\Whatsapp\Support\TransportFailed;

/**
 * Canal d'envoi vers WhatsApp : Meta Cloud API en production, simulation en développement et en test.
 */
interface WhatsappTransport
{
    /**
     * @return string identifiant du message chez WhatsApp (wamid)
     *
     * @throws TransportFailed
     */
    public function sendText(WhatsappAccount $account, string $toE164, string $text): string;

    /**
     * Message modèle (approuvé par Meta) : seul autorisé en dehors de la fenêtre de service de 24 h.
     *
     * @param  list<string>  $parameters  valeurs des variables du corps, dans l'ordre
     * @return string identifiant du message chez WhatsApp (wamid)
     *
     * @throws TransportFailed
     */
    public function sendTemplate(WhatsappAccount $account, string $toE164, string $template, string $language, array $parameters): string;

    /**
     * Vérifie que l'identifiant du numéro et le jeton sont valides chez Meta et renvoie le numéro affiché (connexion, erreur E5).
     *
     * @return array{display_phone: string|null, verified_name: string|null}
     *
     * @throws TransportFailed
     */
    public function verifyNumber(WhatsappAccount $account): array;

    /**
     * Soumet un modèle de message à l'approbation de Meta (catégorie « utilitaire »). Les variables du corps sont `{{1}}`, `{{2}}`…
     *
     * @param  list<string>  $examples  une valeur d'exemple par variable (exigée par Meta)
     *
     * @throws TransportFailed
     */
    public function createTemplate(WhatsappAccount $account, string $name, string $language, string $body, array $examples): TemplateInfo;

    /**
     * Modèles du compte WhatsApp Business et leur statut chez Meta.
     *
     * @return list<TemplateInfo>
     *
     * @throws TransportFailed
     */
    public function listTemplates(WhatsappAccount $account): array;
}
