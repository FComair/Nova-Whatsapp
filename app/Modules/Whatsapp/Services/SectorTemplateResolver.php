<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Services;

use App\Modules\Whatsapp\Models\MessageTemplate;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Support\MessageTemplateStatus;
use App\Modules\Whatsapp\Support\MessageVariables;
use App\Modules\Whatsapp\Support\ResolvedTemplate;

/**
 * Choisit le modèle à envoyer pour un secteur et un déclencheur (docs/39). Un modèle n'est retenu que s'il est :
 *  - de CE secteur (les secteurs sont isolés : jamais le modèle d'un autre),
 *  - du bon type, APPROUVÉ par le Super Admin et ACTIVÉ,
 *  - disponible dans la langue du client (sinon dans la langue de repli de l'application),
 *  - approuvé par Meta pour l'établissement qui envoie (suivi dans `whatsapp_templates`).
 *
 * À défaut, `null` : l'appelant n'envoie rien, ou reprend l'ancien modèle global tant que `whatsapp.catalog.legacy_fallback` est actif.
 * À exécuter sous le contexte de l'organisation (la lecture de `whatsapp_templates` est isolée par établissement).
 */
final class SectorTemplateResolver
{
    /**
     * @param  array<string, string>  $values  valeurs des variables nommées ; une variable sans valeur est remplacée par « - »
     */
    public function resolve(?string $sector, string $type, string $language, array $values): ?ResolvedTemplate
    {
        if ($sector === null || $sector === '') {
            return null;
        }

        $template = MessageTemplate::query()
            ->where('sector_code', $sector)
            ->where('type', $type)
            ->where('status', MessageTemplateStatus::APPROVED)
            ->where('is_enabled', true)
            ->orderBy('created_at')
            ->with('translations')
            ->first();

        if ($template === null) {
            return null;
        }

        $translations = $template->translations->keyBy('language');

        foreach (array_unique([$language, (string) config('app.fallback_locale')]) as $candidate) {
            $translation = $translations->get($candidate);

            if ($translation === null || ! $this->approvedByMeta($template->meta_name, $candidate)) {
                continue;
            }

            $positional = MessageVariables::positional($translation->body);

            return new ResolvedTemplate(
                $template->meta_name,
                $candidate,
                array_map(fn (string $name): string => filled($values[$name] ?? null) ? (string) $values[$name] : '-', $positional['names']),
                $template->category,
            );
        }

        return null;
    }

    private function approvedByMeta(string $name, string $language): bool
    {
        return WhatsappTemplate::query()->where('name', $name)->where('language', $language)->where('status', 'approved')->exists();
    }
}
