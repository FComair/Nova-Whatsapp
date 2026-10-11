<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformFaqArticle;
use App\Modules\Admin\Models\PlatformFaqCategory;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Help\Models\FaqCategory;
use App\Modules\Help\Support\FaqRefused;
use App\Modules\Help\Support\HelpCatalog;

/**
 * Catégories du centre d'aide gérées par le Super Admin (docs/30 §5) : nom et description par langue, icône, ordre, activation.
 * Une catégorie qui contient encore des articles ne se supprime pas : il faut d'abord déplacer, archiver ou réaffecter explicitement
 * ses articles. Chaque action est consignée dans le journal d'audit.
 */
final class FaqCategoryManager
{
    /** Adresses déjà prises par d'autres pages du centre d'aide : une catégorie ne peut pas les porter. */
    public const RESERVED = ['recherche', 'retour', 'avis', 'contact', 'tickets'];

    public function __construct(private readonly HelpCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $data  slug, icon, position, is_active, names[locale], descriptions[locale]
     *
     * @throws FaqRefused
     */
    public function create(PlatformAdmin $admin, array $data): PlatformFaqCategory
    {
        AdminAccess::authorize($admin, 'faq.create');
        $slug = (string) ($data['slug'] ?? '');

        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1 || mb_strlen($slug) > 40 || in_array($slug, self::RESERVED, true)) {
            throw FaqRefused::slugInvalid($slug);
        }

        if (PlatformFaqCategory::query()->where('slug', $slug)->exists()) {
            throw FaqRefused::slugTaken($slug);
        }

        $names = $this->texts($data['names'] ?? []);

        if ($names === []) {
            throw FaqRefused::noContent();
        }

        $category = PlatformFaqCategory::query()->create([
            'slug' => $slug,
            'icon' => $this->icon($data['icon'] ?? null),
            'position' => (int) ($data['position'] ?? 100),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'names' => $names,
            'descriptions' => $this->texts($data['descriptions'] ?? []),
            'updated_by' => $admin->id,
        ]);

        PlatformAudit::record($admin->id, 'faq_category.created', null, 'faq_category', $category->id, ['slug' => $slug, 'names' => $names]);
        $this->catalog->flush();

        return $category;
    }

    /**
     * Modifie une catégorie (le code ne change plus : il fait partie des adresses du centre d'aide).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws FaqRefused
     */
    public function update(PlatformAdmin $admin, PlatformFaqCategory $category, array $data): PlatformFaqCategory
    {
        AdminAccess::authorize($admin, 'faq.edit');
        $before = $this->snapshot($category);

        if (array_key_exists('names', $data)) {
            $names = $this->texts($data['names']);

            if ($names === []) {
                throw FaqRefused::noContent();
            }

            $category->names = $names;
        }

        if (array_key_exists('descriptions', $data)) {
            $category->descriptions = $this->texts($data['descriptions']);
        }

        if (array_key_exists('icon', $data)) {
            $category->icon = $this->icon($data['icon']);
        }

        if (array_key_exists('position', $data)) {
            $category->position = (int) $data['position'];
        }

        if (array_key_exists('is_active', $data)) {
            $category->is_active = (bool) $data['is_active'];
        }

        $category->updated_by = $admin->id;
        $category->save();

        $changes = [];

        foreach ($this->snapshot($category) as $field => $value) {
            if ($value !== $before[$field]) {
                $changes[$field] = [$before[$field], $value];
            }
        }

        if ($changes !== []) {
            PlatformAudit::record($admin->id, 'faq_category.updated', null, 'faq_category', $category->id, ['slug' => $category->slug, ...$changes]);
        }

        if (isset($changes['position'])) {
            PlatformAudit::record($admin->id, 'faq_category.order_changed', null, 'faq_category', $category->id, ['slug' => $category->slug, 'position' => $changes['position']]);
        }

        $this->catalog->flush();

        return $category;
    }

    /**
     * Supprime une catégorie vide. Les catégories livrées avec l'application ne se suppriment pas (elles reviendraient avec leurs textes
     * d'origine) : on les désactive.
     *
     * @throws FaqRefused
     */
    public function delete(PlatformAdmin $admin, PlatformFaqCategory $category): void
    {
        AdminAccess::authorize($admin, 'faq.delete');
        if (array_key_exists($category->slug, (array) config('help.categories'))) {
            throw new FaqRefused('builtin_category');
        }

        $count = PlatformFaqArticle::query()->where('category_id', $category->id)->count();

        if ($count > 0) {
            throw FaqRefused::categoryNotEmpty($count);
        }

        $id = $category->id;
        $summary = ['slug' => $category->slug, 'names' => $category->names];
        $category->delete();

        PlatformAudit::record($admin->id, 'faq_category.deleted', null, 'faq_category', $id, $summary);
        $this->catalog->flush();
    }

    /**
     * Réaffecte explicitement tous les articles d'une catégorie à une autre, puis la supprime. Refusé sans rien changer si une adresse
     * d'article existe déjà dans la catégorie d'accueil.
     *
     * @throws FaqRefused
     */
    public function reassignAndDelete(PlatformAdmin $admin, PlatformFaqCategory $category, PlatformFaqCategory $target): void
    {
        AdminAccess::authorize($admin, 'faq.delete');
        if ($category->id === $target->id) {
            throw FaqRefused::state('reassign');
        }

        $slugs = PlatformFaqArticle::query()->where('category_id', $category->id)->pluck('slug');
        $clash = PlatformFaqArticle::query()->where('category_id', $target->id)->whereIn('slug', $slugs)->value('slug');

        if ($clash !== null) {
            throw FaqRefused::slugTaken((string) $clash);
        }

        $moved = PlatformFaqArticle::query()->where('category_id', $category->id)->update(['category_id' => $target->id, 'last_editor_id' => $admin->id]);
        PlatformAudit::record($admin->id, 'faq_category.articles_moved', null, 'faq_category', $category->id, ['from' => $category->slug, 'to' => $target->slug, 'articles' => $moved]);

        $this->delete($admin, $category);
    }

    /**
     * @return array<string, string>
     */
    private function texts(mixed $texts): array
    {
        $clean = [];

        foreach (FaqCategory::LOCALES as $locale) {
            $value = trim((string) (((array) $texts)[$locale] ?? ''));

            if ($value !== '') {
                $clean[$locale] = mb_substr($value, 0, 200);
            }
        }

        return $clean;
    }

    private function icon(mixed $icon): string
    {
        return in_array($icon, FaqCategory::ICONS, true) ? (string) $icon : 'rocket';
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(PlatformFaqCategory $category): array
    {
        return ['names' => $category->names, 'descriptions' => $category->descriptions, 'icon' => $category->icon, 'position' => $category->position, 'is_active' => $category->is_active];
    }
}
