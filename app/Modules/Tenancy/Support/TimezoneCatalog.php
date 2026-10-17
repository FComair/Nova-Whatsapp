<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use DateTimeZone;

/**
 * Fuseaux horaires proposés à l'inscription : ceux du pays choisi (base IANA). Un pays à plusieurs fuseaux (par exemple la France
 * et ses territoires d'outre-mer) laisse le prestataire choisir le sien ; le fuseau principal du pays vient en premier.
 *
 * Le fuseau retenu pour l'entreprise (`organizations.timezone`) est la référence unique du compte : tous ses sites le suivent,
 * les heures sont enregistrées en UTC et lues / affichées dans ce fuseau.
 */
final class TimezoneCatalog
{
    /**
     * @return list<string>
     */
    public function forCountry(string $country): array
    {
        $country = strtoupper($country);

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return [];
        }

        $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country);
        $main = $this->main($country);

        if ($main !== null && in_array($main, $zones, true)) {
            $zones = [$main, ...array_values(array_diff($zones, [$main]))];
        }

        return $zones;
    }

    /** Fuseau principal du pays, proposé par défaut. */
    public function main(string $country): ?string
    {
        $configured = config('rendivoo.default_timezones.'.strtoupper($country));

        return is_string($configured) ? $configured : (DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, strtoupper($country))[0] ?? null);
    }

    public function accepts(string $country, string $timezone): bool
    {
        return in_array($timezone, $this->forCountry($country), true);
    }

    /**
     * @param  list<string>  $countries
     * @return array<string, list<string>>
     */
    public function byCountry(array $countries): array
    {
        $map = [];

        foreach ($countries as $country) {
            $map[$country] = $this->forCountry($country);
        }

        return $map;
    }
}
