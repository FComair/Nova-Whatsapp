<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

/**
 * Variables dynamiques des messages WhatsApp : `{{customer_name}}` dans le texte saisi, converties en `{{1}}`, `{{2}}`… pour Meta
 * (dans l'ordre d'apparition). Règles de Meta respectées dès la saisie : un message ne commence ni ne finit par une variable,
 * chaque variable n'apparaît qu'une fois, dix variables au plus, 1024 caractères au plus.
 */
final class MessageVariables
{
    public const MAX_LENGTH = 1024;

    public const MAX_VARIABLES = 10;

    public const NAMES = [
        'business_name', 'customer_name', 'service_name', 'practitioner_name', 'date', 'time', 'address', 'booking_link', 'amount', 'payment_link', 'review_link',
    ];

    private const PATTERN = '/\{\{\s*([a-z_]+)\s*\}\}/';

    /** @var array<string, array<string, string>> */
    private const SAMPLES = [
        'fr' => [
            'business_name' => 'Salon Aurore', 'customer_name' => 'Marie', 'service_name' => 'Coupe', 'practitioner_name' => 'Julie',
            'date' => 'jeudi 15/10/2026', 'time' => '14:00', 'address' => 'Rue de la Loi 16, 1000 Bruxelles', 'booking_link' => 'https://rendivoo.example/r/aurore',
            'amount' => '45,00 €', 'payment_link' => 'https://rendivoo.example/p/4f2a', 'review_link' => 'https://rendivoo.example/a/4f2a',
        ],
        'nl' => [
            'business_name' => 'Salon Aurore', 'customer_name' => 'Marie', 'service_name' => 'Knippen', 'practitioner_name' => 'Julie',
            'date' => 'donderdag 15/10/2026', 'time' => '14:00', 'address' => 'Wetstraat 16, 1000 Brussel', 'booking_link' => 'https://rendivoo.example/r/aurore',
            'amount' => '€ 45,00', 'payment_link' => 'https://rendivoo.example/p/4f2a', 'review_link' => 'https://rendivoo.example/a/4f2a',
        ],
        'en' => [
            'business_name' => 'Salon Aurore', 'customer_name' => 'Marie', 'service_name' => 'Haircut', 'practitioner_name' => 'Julie',
            'date' => 'Thursday 15/10/2026', 'time' => '14:00', 'address' => '16 Rue de la Loi, 1000 Brussels', 'booking_link' => 'https://rendivoo.example/r/aurore',
            'amount' => '€45.00', 'payment_link' => 'https://rendivoo.example/p/4f2a', 'review_link' => 'https://rendivoo.example/a/4f2a',
        ],
    ];

    /**
     * Noms des variables utilisées, dans l'ordre d'apparition (doublons inclus).
     *
     * @return list<string>
     */
    public static function extract(string $body): array
    {
        preg_match_all(self::PATTERN, $body, $matches);

        return array_values($matches[1]);
    }

    /**
     * Problèmes du texte, sous forme de codes (« unknown:xxx », « duplicate:xxx », « empty », « too_long », « too_many »,
     * « starts_with_variable », « ends_with_variable », « malformed ») ; liste vide si le texte est valide.
     *
     * @return list<string>
     */
    public static function problems(string $body): array
    {
        $body = trim($body);

        if ($body === '') {
            return ['empty'];
        }

        $problems = [];
        $names = self::extract($body);

        if (mb_strlen($body) > self::MAX_LENGTH) {
            $problems[] = 'too_long';
        }

        if (count($names) > self::MAX_VARIABLES) {
            $problems[] = 'too_many';
        }

        foreach (array_unique($names) as $name) {
            if (! in_array($name, self::NAMES, true)) {
                $problems[] = 'unknown:'.$name;
            }
        }

        foreach (array_keys(array_filter(array_count_values($names), fn (int $count): bool => $count > 1)) as $name) {
            $problems[] = 'duplicate:'.$name;
        }

        if (preg_match('/^\{\{[^}]*\}\}/', $body) === 1) {
            $problems[] = 'starts_with_variable';
        }

        if (preg_match('/\{\{[^}]*\}\}[\s.!?]*$/u', $body) === 1) {
            $problems[] = 'ends_with_variable';
        }

        if (str_contains((string) preg_replace(self::PATTERN, '', $body), '{{') || str_contains((string) preg_replace(self::PATTERN, '', $body), '}}')) {
            $problems[] = 'malformed';
        }

        return $problems;
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function render(string $body, array $values): string
    {
        return (string) preg_replace_callback(self::PATTERN, fn (array $match): string => $values[$match[1]] ?? '', $body);
    }

    /**
     * Texte pour Meta : variables numérotées dans l'ordre d'apparition, avec la liste des noms correspondants.
     *
     * @return array{body: string, names: list<string>}
     */
    public static function positional(string $body): array
    {
        $names = [];
        $text = (string) preg_replace_callback(self::PATTERN, function (array $match) use (&$names): string {
            $names[] = $match[1];

            return '{{'.count($names).'}}';
        }, $body);

        return ['body' => $text, 'names' => $names];
    }

    /**
     * Valeurs d'exemple pour l'aperçu et pour Meta.
     *
     * @return array<string, string>
     */
    public static function sample(string $language, ?string $service = null): array
    {
        $values = self::SAMPLES[$language] ?? self::SAMPLES['fr'];

        return $service === null ? $values : ['service_name' => $service] + $values;
    }
}
