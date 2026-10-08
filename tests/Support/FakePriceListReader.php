<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Contracts\PriceListReader;

/** Lecteur de liste de prix des tests : renvoie les lignes préparées par le test, sans appel réseau. */
final class FakePriceListReader implements PriceListReader
{
    /** @var list<array<string, mixed>>|null */
    public static ?array $lines = [];

    public static bool $available = true;

    /** @var list<string> */
    public static array $received = [];

    public function available(): bool
    {
        return self::$available;
    }

    public function read(array $images, string $locale): ?array
    {
        self::$received = $images;

        return self::$lines;
    }

    public static function reset(): void
    {
        self::$lines = [];
        self::$available = true;
        self::$received = [];
    }
}
