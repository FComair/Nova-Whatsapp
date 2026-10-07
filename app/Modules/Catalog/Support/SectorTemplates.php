<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * Modèles de services par secteur (config/sector_templates.php), traduits dans la langue demandée.
 * Les valeurs (durées, prix, TVA) sont indicatives : le propriétaire les ajuste.
 */
final class SectorTemplates
{
    public static function has(string $sector): bool
    {
        return isset(config('sector_templates')[$sector]);
    }

    /**
     * @return list<array{key: string, category: string, category_key: string, name: string, duration: int, price: int, vat: int}>
     */
    public static function services(string $sector, string $locale): array
    {
        /** @var array{categories: list<string>, services: array<string, array{category: string, duration: int, price: int, vat: int}>}|null $template */
        $template = config('sector_templates')[$sector] ?? null;

        if ($template === null) {
            return [];
        }

        $services = [];

        foreach ($template['services'] as $key => $definition) {
            $services[] = [
                'key' => $key,
                'category_key' => $definition['category'],
                'category' => __("sector_templates.{$sector}.categories.{$definition['category']}", [], $locale),
                'name' => __("sector_templates.{$sector}.services.{$key}", [], $locale),
                'duration' => $definition['duration'],
                'price' => $definition['price'],
                'vat' => $definition['vat'],
            ];
        }

        return $services;
    }
}
