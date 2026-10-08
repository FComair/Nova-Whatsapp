<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/**
 * Lecteur de listes de prix : transforme les images d'une liste en lignes (service, prix…). Les montants sont des nombres décimaux,
 * pas encore des centimes ; la normalisation et les contrôles sont faits par PriceListAnalyzer.
 */
interface PriceListReader
{
    /** Faux quand aucune clé n'est configurée : l'import n'est alors pas proposé. */
    public function available(): bool;

    /**
     * @param  list<string>  $images  images JPEG (octets), dans l'ordre des pages
     * @return list<array{name: string, price: float|int|string|null, category?: string|null, duration_minutes?: int|string|null, vat_rate?: int|string|null, unit?: string|null, currency?: string|null, confidence?: float|int|string|null}>|null
     *                                                                                                                                                                                                                                              les lignes lues (liste vide : aucun prix repéré), ou null si le document n'a pas pu être lu
     */
    public function read(array $images, string $locale): ?array;
}
