<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

use App\Modules\Whatsapp\Models\MessageTemplate;

/**
 * Les modèles de messages que Rendivoo fait suivre chez Meta pour un établissement.
 *
 * - Les quatre modèles globaux (rappel, confirmation, déplacement, annulation) dans chaque langue de l'interface : noms
 *   `config('whatsapp.templates')`, textes `lang/{fr,nl,en}/whatsapp.php`. Ils restent la base tant que le secteur n'a rien d'approuvé.
 * - Les modèles APPROUVÉS et activés de la bibliothèque du secteur de l'établissement (docs/39), nom Meta préfixé par le secteur. Pour un
 *   message et une langue donnés, le modèle du secteur remplace le modèle global.
 *
 * Seuls les quatre messages automatiques sont « requis » (l'assistant d'installation attend qu'ils soient approuvés par Meta).
 */
final class TemplateCatalog
{
    public const KEYS = ['reminder', 'confirmation', 'rescheduled', 'cancellation'];

    /**
     * @return list<array{key: string, name: string, language: string, body: string, examples: list<string>, required: bool}>
     */
    public static function entries(?string $sector = null): array
    {
        $entries = [];

        foreach (self::KEYS as $key) {
            foreach ((array) config('rendivoo.locales') as $language) {
                $entries[$key.'|'.$language] = [
                    'key' => $key,
                    'name' => (string) config("whatsapp.templates.{$key}"),
                    'language' => (string) $language,
                    'body' => self::body($key, (string) $language),
                    'examples' => self::examples((string) $language),
                    'required' => true,
                ];
            }
        }

        foreach (self::fromLibrary($sector) as $entry) {
            $entries[$entry['key'].'|'.$entry['language']] = $entry;
        }

        return array_values($entries);
    }

    public static function body(string $key, string $language): string
    {
        return (string) trans("whatsapp.templates.{$key}", [], $language);
    }

    /**
     * @return list<string>
     */
    public static function examples(string $language): array
    {
        /** @var list<string> $examples */
        $examples = array_values((array) trans('whatsapp.examples', [], $language));

        return $examples;
    }

    /**
     * @return list<array{key: string, name: string, language: string, body: string, examples: list<string>, required: bool}>
     */
    private static function fromLibrary(?string $sector): array
    {
        if ($sector === null || $sector === '') {
            return [];
        }

        $legacyKeys = MessageTemplateType::AUTOMATIC;
        $entries = [];

        $templates = MessageTemplate::query()
            ->where('sector_code', $sector)
            ->where('status', MessageTemplateStatus::APPROVED)
            ->where('is_enabled', true)
            ->orderBy('created_at')
            ->with('translations')
            ->get();

        foreach ($templates as $template) {
            $key = $legacyKeys[$template->type] ?? $template->type;

            foreach ($template->translations as $translation) {
                // Un seul modèle par message et par langue : le plus ancien approuvé du secteur.
                if (isset($entries[$key.'|'.$translation->language])) {
                    continue;
                }

                $positional = MessageVariables::positional($translation->body);
                $samples = MessageVariables::sample($translation->language, MessageTemplateLibrary::sampleService($sector, $translation->language));

                $entries[$key.'|'.$translation->language] = [
                    'key' => $key,
                    'name' => $template->meta_name,
                    'language' => $translation->language,
                    'body' => $positional['body'],
                    'examples' => array_map(fn (string $name): string => $samples[$name] ?? '-', $positional['names']),
                    'required' => in_array($key, self::KEYS, true),
                ];
            }
        }

        return array_values($entries);
    }
}
