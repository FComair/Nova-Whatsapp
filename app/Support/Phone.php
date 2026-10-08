<?php

declare(strict_types=1);

namespace App\Support;

use libphonenumber\NumberParseException;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * Lecture d'un numéro saisi à la main, sans jamais deviner un indicatif de pays.
 *
 * Un numéro écrit à l'international (« +31 6 12 34 56 78 » ou « 0031… ») est lu tel quel. Un numéro national (« 06 12 34 56 78 »)
 * peut être valide dans plusieurs pays (le 06 existe en France et aux Pays-Bas) : il est lu dans le pays de l'établissement quand
 * on le connaît, sinon seulement s'il n'est valide que dans un seul pays de la liste. Dans le doute on refuse et on demande
 * l'indicatif : mieux vaut une question qu'un client enregistré sous le mauvais pays (messages WhatsApp perdus).
 */
final class Phone
{
    public const COUNTRIES = ['BE', 'FR', 'NL', 'LU', 'DE', 'GB'];

    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const AMBIGUOUS = 'ambiguous';

    /** « +… » ou « 00… » : le pays est dans le numéro. */
    public static function isInternational(string $input): bool
    {
        $input = ltrim($input);

        return str_starts_with($input, '+') || str_starts_with(preg_replace('/[\s().\-]/', '', $input) ?? '', '00');
    }

    /**
     * @param  list<string>  $countries  pays acceptés
     * @return array{e164: string|null, problem: string}
     */
    public static function resolve(string $input, ?string $preferred = null, array $countries = self::COUNTRIES): array
    {
        $input = trim($input);

        if ($input === '') {
            return ['e164' => null, 'problem' => self::INVALID];
        }

        if (self::isInternational($input)) {
            $digits = preg_replace('/[\s().\-]/', '', $input) ?? '';
            $number = self::parse(str_starts_with($digits, '00') ? '+'.substr($digits, 2) : $input);

            return $number !== null && $number->isValid() && in_array($number->getCountry(), $countries, true)
                ? ['e164' => $number->formatE164(), 'problem' => self::OK]
                : ['e164' => null, 'problem' => self::INVALID];
        }

        if ($preferred !== null && in_array($preferred, $countries, true)) {
            $number = self::parse($input, $preferred);

            return $number !== null && $number->isValid()
                ? ['e164' => $number->formatE164(), 'problem' => self::OK]
                : ['e164' => null, 'problem' => self::INVALID];
        }

        $matches = [];

        foreach ($countries as $country) {
            $number = self::parse($input, $country);

            if ($number !== null && $number->isValid()) {
                $matches[$number->formatE164()] = true;
            }
        }

        return match (count($matches)) {
            0 => ['e164' => null, 'problem' => self::INVALID],
            1 => ['e164' => (string) array_key_first($matches), 'problem' => self::OK],
            default => ['e164' => null, 'problem' => self::AMBIGUOUS],
        };
    }

    public static function toE164(string $input, ?string $preferred = null): ?string
    {
        return self::resolve($input, $preferred)['e164'];
    }

    private static function parse(string $input, ?string $country = null): ?PhoneNumber
    {
        try {
            $number = new PhoneNumber($input, $country);
            $number->getCountry();

            return $number;
        } catch (NumberParseException) {
            return null;
        }
    }
}
