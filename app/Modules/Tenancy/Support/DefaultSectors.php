<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Les secteurs d'activité de départ (ceux qui existaient avant que le Super Admin puisse les gérer), avec les MÊMES codes :
 * les établissements et les modèles de services y restent rattachés. `other` est le secteur par défaut, jamais supprimé ni désactivé.
 *
 * Utilisé par la migration de création de la table et par les tests (remise à l'état initial).
 */
final class DefaultSectors
{
    /** Code du secteur de repli : reçoit les établissements d'un secteur supprimé. */
    public const FALLBACK = 'other';

    /**
     * @return list<array{code: string, name: string, name_fr: string, name_nl: string}>
     */
    public static function rows(): array
    {
        return [
            ['code' => 'hairdressing', 'name' => 'Hairdresser & Barber', 'name_fr' => 'Coiffeur & Barbier', 'name_nl' => 'Kapper & Barbier'],
            ['code' => 'beauty', 'name' => 'Beauty and aesthetics', 'name_fr' => 'Beauté et esthétique', 'name_nl' => 'Schoonheid en esthetiek'],
            ['code' => 'wellness', 'name' => 'Wellness and spa', 'name_fr' => 'Bien-être et spa', 'name_nl' => 'Wellness en spa'],
            ['code' => 'medical', 'name' => 'General and specialist medicine', 'name_fr' => 'Médecine générale et spécialisée', 'name_nl' => 'Huisarts en specialist'],
            ['code' => 'paramedical', 'name' => 'Paramedical (physio, etc.)', 'name_fr' => 'Paramédical (kiné, etc.)', 'name_nl' => 'Paramedisch (kine, enz.)'],
            ['code' => 'dental', 'name' => 'Dentist', 'name_fr' => 'Dentiste', 'name_nl' => 'Tandarts'],
            ['code' => 'coaching', 'name' => 'Coaching and consulting', 'name_fr' => 'Coaching et conseil', 'name_nl' => 'Coaching en advies'],
            ['code' => 'real_estate', 'name' => 'Real estate', 'name_fr' => 'Immobilier', 'name_nl' => 'Vastgoed'],
            ['code' => 'garage', 'name' => 'Garage and automotive', 'name_fr' => 'Garage et automobile', 'name_nl' => 'Garage en auto'],
            ['code' => 'driving_school', 'name' => 'Driving school', 'name_fr' => 'Auto-école', 'name_nl' => 'Rijschool'],
            ['code' => self::FALLBACK, 'name' => 'Other', 'name_fr' => 'Autre', 'name_nl' => 'Andere'],
        ];
    }

    /** Insère les secteurs de départ (table vide). Le rôle de connexion doit pouvoir écrire dans `sectors`. */
    public static function insert(?string $connection = null): void
    {
        DB::connection($connection)->table('sectors')->insert(array_map(fn (array $row): array => [
            'id' => (string) Str::uuid7(),
            ...$row,
            'name_key' => self::key($row['name']),
            'is_active' => true,
            'is_default' => $row['code'] === self::FALLBACK,
            'created_at' => now(),
            'updated_at' => now(),
        ], self::rows()));
    }

    /**
     * Clé de comparaison d'un nom : sans accents, sans majuscules, espaces simplifiés. Deux noms de même clé sont le même secteur
     * (« Food & Beverage », « food  & beverage » et « Fòod & Beverage » sont identiques).
     */
    public static function key(string $name): string
    {
        return (string) Str::of($name)->ascii()->lower()->squish();
    }
}
