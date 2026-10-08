<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Illuminate\Support\Str;

/**
 * Rapprochement des lignes lues avec les services existants.
 *
 * Les noms sont comparés sans casse, sans accents ni ponctuation, mots de liaison retirés. Le score est le meilleur de deux mesures :
 * la ressemblance des lettres (distance d'édition) et celle des mots (coefficient de Dice).
 * - 90 % ou plus (ou noms identiques) : la ligne met à jour ce service ;
 * - de 70 à 89 % : rien n'est appliqué, le service proche est seulement proposé ;
 * - en dessous : nouveau service.
 * Un service n'est rapproché que d'une seule ligne (la plus ressemblante) : jamais deux mises à jour du même service.
 */
final class PriceListMatcher
{
    private const FILLER = ['de', 'du', 'des', 'la', 'le', 'les', 'un', 'une', 'et', 'the', 'a', 'an', 'and', 'for', 'voor', 'van', 'het', 'een', 'en', 'pour', 'avec', 'with', 'met', 's'];

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array{id: string, name: string}>  $services
     * @return list<array<string, mixed>> les lignes, avec `match`, `suggestion` et `action` renseignés
     */
    public function match(array $lines, array $services): array
    {
        $auto = (float) config('pricelist.match_auto');
        $suggest = (float) config('pricelist.match_suggest');

        $pairs = [];

        foreach ($lines as $index => $line) {
            foreach ($services as $service) {
                $score = $this->similarity((string) $line['name'], $service['name']);

                if ($score >= $suggest) {
                    $pairs[] = ['line' => $index, 'service' => $service, 'score' => $score];
                }
            }
        }

        usort($pairs, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $usedLines = [];
        $usedServices = [];

        foreach ($lines as $index => $line) {
            $lines[$index]['match'] = null;
            $lines[$index]['suggestion'] = null;
        }

        foreach ($pairs as $pair) {
            $index = $pair['line'];
            $serviceId = $pair['service']['id'];

            if (isset($usedLines[$index]) || isset($usedServices[$serviceId])) {
                continue;
            }

            $entry = ['service_id' => $serviceId, 'name' => $pair['service']['name'], 'score' => round($pair['score'], 2)];
            $usedLines[$index] = true;

            if ($pair['score'] >= $auto) {
                $usedServices[$serviceId] = true;
                $lines[$index]['match'] = $entry;
            } else {
                $lines[$index]['suggestion'] = $entry;
            }
        }

        foreach ($lines as $index => $line) {
            $lines[$index]['action'] = match (true) {
                ($line['ignored_reason'] ?? null) !== null => 'ignore',
                $lines[$index]['match'] !== null => 'update',
                default => 'new',
            };
        }

        return $lines;
    }

    public function similarity(string $a, string $b): float
    {
        $first = $this->normalize($a);
        $second = $this->normalize($b);

        if ($first === '' || $second === '') {
            return 0.0;
        }

        if ($first === $second) {
            return 1.0;
        }

        $length = max(strlen($first), strlen($second));
        $letters = $length > 255 ? 0.0 : 1 - levenshtein($first, $second) / $length;

        $wordsA = array_values(array_unique(explode(' ', $first)));
        $wordsB = array_values(array_unique(explode(' ', $second)));
        $words = 2 * count(array_intersect($wordsA, $wordsB)) / (count($wordsA) + count($wordsB));

        return max($letters, $words);
    }

    /** Nom sans casse, accents, ponctuation ni mots de liaison (« Coupe de l'homme » → « coupe homme »). */
    public function normalize(string $name): string
    {
        $clean = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($name))));
        $words = array_filter(explode(' ', $clean), static fn (string $word): bool => $word !== '');
        $kept = array_values(array_filter($words, static fn (string $word): bool => ! in_array($word, self::FILLER, true)));

        return implode(' ', $kept === [] ? array_values($words) : $kept);
    }
}
