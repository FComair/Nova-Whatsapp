<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

/**
 * Les modèles de messages que Nova utilise (rappel, confirmation, déplacement, annulation), dans chaque langue de l'interface.
 * Noms : `config('whatsapp.templates')` ; textes : `lang/{fr,nl,en}/whatsapp.php`.
 */
final class TemplateCatalog
{
    public const KEYS = ['reminder', 'confirmation', 'rescheduled', 'cancellation'];

    /**
     * @return list<array{key: string, name: string, language: string}>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach (self::KEYS as $key) {
            foreach ((array) config('nova.locales') as $language) {
                $entries[] = ['key' => $key, 'name' => (string) config("whatsapp.templates.{$key}"), 'language' => (string) $language];
            }
        }

        return $entries;
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
}
