<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Support;

/**
 * Messages WhatsApp du programme de fidélité (docs/27) : les cinq occasions, leurs variables, les textes par défaut (lang/*\/loyalty.php)
 * et le remplacement des variables. Le même remplacement sert à l'aperçu de l'écran et à l'envoi.
 */
final class LoyaltyMessages
{
    /** Occasions, dans l'ordre d'affichage. `joined` : inscription ; `progress` : après un tampon ; puis récompense obtenue, utilisée, bientôt expirée. */
    public const KINDS = ['joined', 'progress', 'reward_earned', 'reward_redeemed', 'expiry_reminder'];

    /** Variables acceptées dans un texte, écrites {nom}. */
    public const VARIABLES = ['name', 'business', 'program', 'progress', 'stamps', 'required', 'remaining', 'reward', 'expiry_date'];

    public const MAX_LENGTH = 600;

    /**
     * Réglages par défaut : rien n'est envoyé tant que l'établissement n'a pas activé un message.
     *
     * @return array<string, array{enabled: bool, texts: array<string, string>}>
     */
    public static function defaults(): array
    {
        $settings = [];

        foreach (self::KINDS as $kind) {
            $settings[$kind] = ['enabled' => false, 'texts' => ['fr' => '', 'nl' => '', 'en' => '']];
        }

        return $settings;
    }

    /**
     * Texte à envoyer dans une langue : celui de l'établissement s'il l'a personnalisé, sinon le texte par défaut de la plateforme.
     *
     * @param  array<string, mixed>  $settings  réglages enregistrés du programme (`messages`)
     */
    public static function text(array $settings, string $kind, string $language): string
    {
        $custom = trim((string) ($settings[$kind]['texts'][$language] ?? ''));

        return $custom !== '' ? $custom : self::defaultText($kind, $language);
    }

    public static function defaultText(string $kind, string $language): string
    {
        return (string) trans("loyalty.messages.default.{$kind}", [], $language);
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function render(string $text, array $values): string
    {
        $replace = [];

        foreach ($values as $name => $value) {
            $replace['{'.$name.'}'] = $value;
        }

        return trim(strtr($text, $replace));
    }

    /** Variables inconnues d'un texte (écrites {x} sans être dans la liste) : refusées à l'enregistrement pour ne jamais envoyer « {nom} » tel quel. */
    /** @return list<string> */
    public static function unknownVariables(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/i', $text, $found);

        return array_values(array_unique(array_filter($found[1], fn (string $name): bool => ! in_array($name, self::VARIABLES, true))));
    }

    /**
     * Valeurs d'exemple pour l'aperçu, dans une langue.
     *
     * @return array<string, string>
     */
    public static function samples(string $language): array
    {
        /** @var array<string, string> $samples */
        $samples = (array) trans('loyalty.messages.samples', [], $language);

        return $samples;
    }
}
