<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les catégories de services ont une langue (fr, nl ou en) : la liste proposée dans un formulaire ne montre que les catégories
 * de la langue de l'interface, au lieu de mélanger « Hair », « Cheveux » et « Haar ».
 *
 * Les catégories existantes reçoivent leur langue : celle du modèle sectoriel dont elles portent le nom, sinon la langue de
 * l'entreprise. Un même nom peut désormais exister dans deux langues (unicité par entreprise, langue et nom).
 */
return new class extends Migration
{
    private const LOCALES = ['fr', 'nl', 'en'];

    public function up(): void
    {
        DB::statement("ALTER TABLE service_categories ADD COLUMN locale varchar(2) NOT NULL DEFAULT 'fr'");
        DB::statement("ALTER TABLE service_categories ADD CONSTRAINT service_categories_locale_check CHECK (locale IN ('fr','nl','en'))");

        $this->backfill();

        DB::statement('DROP INDEX IF EXISTS service_categories_name_unique');
        DB::statement('CREATE UNIQUE INDEX service_categories_name_unique ON service_categories (org_id, locale, lower(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS service_categories_name_unique');
        DB::statement('CREATE UNIQUE INDEX service_categories_name_unique ON service_categories (org_id, lower(name))');
        DB::statement('ALTER TABLE service_categories DROP CONSTRAINT IF EXISTS service_categories_locale_check');
        DB::statement('ALTER TABLE service_categories DROP COLUMN IF EXISTS locale');
    }

    /** Donne sa langue à chaque catégorie existante. La RLS est suspendue le temps de la migration (comme pour les clients). */
    private function backfill(): void
    {
        $known = $this->templateNames();

        DB::statement('ALTER TABLE service_categories NO FORCE ROW LEVEL SECURITY');

        $organizationLocales = DB::table('organizations')->pluck('locale', 'id');

        foreach (DB::table('service_categories')->get(['id', 'org_id', 'name']) as $category) {
            $fallback = in_array($organizationLocales[$category->org_id] ?? null, self::LOCALES, true) ? $organizationLocales[$category->org_id] : 'fr';
            $matches = $known[mb_strtolower($category->name)] ?? [];
            // Un nom propre à une seule langue donne cette langue ; commun à plusieurs, on garde celle de l'entreprise si elle en fait partie.
            $locale = count($matches) === 1 ? $matches[0] : (in_array($fallback, $matches, true) ? $fallback : ($matches[0] ?? $fallback));

            DB::table('service_categories')->where('id', $category->id)->update(['locale' => $locale]);
        }

        DB::statement('ALTER TABLE service_categories FORCE ROW LEVEL SECURITY');
    }

    /**
     * @return array<string, list<string>> nom de catégorie en minuscules => langues des modèles sectoriels où il apparaît
     */
    private function templateNames(): array
    {
        $names = [];

        foreach (self::LOCALES as $locale) {
            $sectors = trans('sector_templates', [], $locale);

            foreach (is_array($sectors) ? $sectors : [] as $sector) {
                foreach (is_array($sector) ? ($sector['categories'] ?? []) : [] as $name) {
                    if (is_string($name)) {
                        $names[mb_strtolower($name)][] = $locale;
                    }
                }
            }
        }

        return array_map(fn (array $locales): array => array_values(array_unique($locales)), $names);
    }
};
