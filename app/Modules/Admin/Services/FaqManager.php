<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformFaqArticle;
use App\Modules\Admin\Models\PlatformFaqCategory;
use App\Modules\Admin\Models\PlatformFaqTranslation;
use App\Modules\Help\Models\FaqArticle;
use App\Modules\Help\Models\FaqCategory;
use App\Modules\Help\Models\FaqVersion;
use App\Modules\Help\Support\FaqPublishing;
use App\Modules\Help\Support\FaqRefused;
use App\Modules\Help\Support\HelpCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Création, modification et publication des FAQ par le Super Admin (docs/30). Point d'entrée unique des écrans : applique les règles de
 * publication (langues prêtes), garde l'historique des textes (versions, ajout seul), consigne chaque action dans le journal d'audit de
 * la plateforme et invalide la lecture du centre d'aide. Tout s'écrit avec le rôle `nova_platform`.
 */
final class FaqManager
{
    public const TRANSLATION_FIELDS = ['question', 'summary', 'body', 'is_ready'];

    public function __construct(private readonly HelpCatalog $catalog) {}

    /**
     * Crée une FAQ en brouillon. Au moins une langue doit avoir une question.
     *
     * @param  array<string, mixed>  $data  category_id, slug?, position?, is_popular?, publication?, tags?, related?, translations?
     *
     * @throws FaqRefused
     */
    public function create(PlatformAdmin $admin, array $data): PlatformFaqArticle
    {
        $translations = $this->cleanTranslations((array) ($data['translations'] ?? []));
        $first = collect($translations)->first(fn (array $fields): bool => $fields['question'] !== '');

        if ($first === null) {
            throw FaqRefused::noContent();
        }

        $category = PlatformFaqCategory::query()->findOrFail((string) $data['category_id']);
        $slug = $this->slug((string) ($data['slug'] ?? ''), $first['question'], $category->id);

        $article = (new PlatformFaqArticle)->getConnection()->transaction(function () use ($admin, $data, $category, $slug, $translations): PlatformFaqArticle {
            $article = PlatformFaqArticle::query()->create([
                'category_id' => $category->id,
                'slug' => $slug,
                'status' => FaqArticle::DRAFT,
                'publication' => $this->mode($data['publication'] ?? null),
                'position' => (int) ($data['position'] ?? 100),
                'is_popular' => (bool) ($data['is_popular'] ?? false),
                'tags' => $this->tags($data['tags'] ?? []),
                'author_id' => $admin->id,
                'last_editor_id' => $admin->id,
            ]);

            foreach ($translations as $locale => $fields) {
                $this->saveTranslation($admin, $article, $locale, $fields);
            }

            $this->syncRelated($article, (array) ($data['related'] ?? []));

            return $article;
        });

        $article->load(['category', 'translations']);
        $this->audit($admin, 'faq.created', $article, [
            'slug' => $article->slug, 'category' => $category->slug, 'languages' => array_keys(array_filter($translations, fn (array $f): bool => $f['question'] !== '')),
        ]);
        $this->catalog->flush();

        return $article;
    }

    /**
     * Modifie une FAQ (fiche et traductions). Une FAQ publiée doit rester publiable : on ne retire pas une langue obligatoire sous elle.
     *
     * @param  array<string, mixed>  $data  mêmes clés que `create` ; une clé absente n'est pas modifiée
     *
     * @throws FaqRefused
     */
    public function update(PlatformAdmin $admin, PlatformFaqArticle $article, array $data): PlatformFaqArticle
    {
        $article->load(['category', 'translations', 'related.category']);
        $before = $this->snapshot($article);
        $translations = array_key_exists('translations', $data) ? $this->cleanTranslations((array) $data['translations']) : [];
        $events = [];

        $article->getConnection()->transaction(function () use ($admin, $article, $data, $translations, &$events): void {
            if (isset($data['category_id']) && $data['category_id'] !== $article->category_id) {
                $category = PlatformFaqCategory::query()->findOrFail((string) $data['category_id']);
                $this->assertSlugFree($article->slug, $category->id, $article->id);
                $article->category_id = $category->id;
            }

            if (isset($data['slug']) && $data['slug'] !== '' && $data['slug'] !== $article->slug) {
                $this->assertSlugValid((string) $data['slug']);
                $this->assertSlugFree((string) $data['slug'], $article->category_id, $article->id);
                $article->slug = (string) $data['slug'];
            }

            foreach (['position' => 'int', 'is_popular' => 'bool'] as $field => $cast) {
                if (array_key_exists($field, $data)) {
                    $article->{$field} = $cast === 'int' ? (int) $data[$field] : (bool) $data[$field];
                }
            }

            if (array_key_exists('publication', $data)) {
                $article->publication = $this->mode($data['publication']);
            }

            if (array_key_exists('tags', $data)) {
                $article->tags = $this->tags($data['tags']);
            }

            $article->last_editor_id = $admin->id;
            $article->save();

            foreach ($translations as $locale => $fields) {
                $result = $this->saveTranslation($admin, $article, $locale, $fields);

                if ($result !== null) {
                    $events[] = $result;
                }
            }

            if (array_key_exists('related', $data)) {
                $this->syncRelated($article, (array) $data['related']);
            }

            $article->unsetRelation('translations')->load('translations');

            if ($article->status === FaqArticle::PUBLISHED && ($missing = FaqPublishing::missing($article)) !== []) {
                throw FaqRefused::missingTranslations($missing);
            }
        });

        $article->refresh()->load(['category', 'translations', 'related.category']);
        $after = $this->snapshot($article);
        $changes = [];

        foreach ($after as $field => $value) {
            if ($value !== $before[$field]) {
                $changes[$field] = [$before[$field], $value];
            }
        }

        if ($changes !== []) {
            $this->audit($admin, 'faq.updated', $article, $changes);
        }

        if (isset($changes['position'])) {
            $this->audit($admin, 'faq.order_changed', $article, ['position' => $changes['position']]);
        }

        foreach ($events as $event) {
            $this->audit($admin, $event['created'] ? 'faq.translation_added' : 'faq.translation_updated', $article, ['locale' => $event['locale'], 'fields' => $event['fields']]);
        }

        $this->catalog->flush();

        return $article;
    }

    /**
     * Publie : toutes les langues obligatoires du mode choisi doivent être prêtes.
     *
     * @throws FaqRefused
     */
    public function publish(PlatformAdmin $admin, PlatformFaqArticle $article): void
    {
        $article->load(['category', 'translations']);

        if ($article->status === FaqArticle::PUBLISHED) {
            throw FaqRefused::state('publish');
        }

        if (($missing = FaqPublishing::missing($article)) !== []) {
            throw FaqRefused::missingTranslations($missing);
        }

        $from = $article->status;
        $article->forceFill(['status' => FaqArticle::PUBLISHED, 'published_at' => now(), 'archived_at' => null, 'last_editor_id' => $admin->id])->save();
        $this->audit($admin, 'faq.published', $article, ['status' => [$from, FaqArticle::PUBLISHED], 'languages' => $article->readyLocales(), 'mode' => $article->publication]);
        $this->catalog->flush();
    }

    /** @throws FaqRefused */
    public function unpublish(PlatformAdmin $admin, PlatformFaqArticle $article): void
    {
        if ($article->status !== FaqArticle::PUBLISHED) {
            throw FaqRefused::state('unpublish');
        }

        $article->forceFill(['status' => FaqArticle::DRAFT, 'last_editor_id' => $admin->id])->save();
        $this->audit($admin, 'faq.unpublished', $article, ['status' => [FaqArticle::PUBLISHED, FaqArticle::DRAFT]]);
        $this->catalog->flush();
    }

    /** @throws FaqRefused */
    public function archive(PlatformAdmin $admin, PlatformFaqArticle $article): void
    {
        if ($article->status === FaqArticle::ARCHIVED) {
            throw FaqRefused::state('archive');
        }

        $from = $article->status;
        $article->forceFill(['status' => FaqArticle::ARCHIVED, 'archived_at' => now(), 'last_editor_id' => $admin->id])->save();
        $this->audit($admin, 'faq.archived', $article, ['status' => [$from, FaqArticle::ARCHIVED]]);
        $this->catalog->flush();
    }

    /** Sort une FAQ des archives : elle redevient un brouillon. @throws FaqRefused */
    public function restore(PlatformAdmin $admin, PlatformFaqArticle $article): void
    {
        if ($article->status !== FaqArticle::ARCHIVED) {
            throw FaqRefused::state('restore');
        }

        $article->forceFill(['status' => FaqArticle::DRAFT, 'archived_at' => null, 'last_editor_id' => $admin->id])->save();
        $this->audit($admin, 'faq.restored', $article, ['status' => [FaqArticle::ARCHIVED, FaqArticle::DRAFT]]);
        $this->catalog->flush();
    }

    /**
     * Supprime définitivement un brouillon ou une FAQ archivée (une FAQ publiée doit d'abord être dépubliée ou archivée). Le journal
     * garde ce qu'elle était.
     *
     * @throws FaqRefused
     */
    public function delete(PlatformAdmin $admin, PlatformFaqArticle $article): void
    {
        if ($article->status === FaqArticle::PUBLISHED) {
            throw FaqRefused::state('delete');
        }

        $article->load(['category', 'translations']);
        $summary = ['slug' => $article->slug, 'category' => $article->category->slug, 'status' => $article->status, 'questions' => $article->translations->pluck('question', 'locale')->all()];
        $id = $article->id;
        $article->delete();

        PlatformAudit::record($admin->id, 'faq.deleted', null, 'faq_article', $id, $summary);
        $this->catalog->flush();
    }

    /** Copie en brouillon, traductions non prêtes (à relire). */
    public function duplicate(PlatformAdmin $admin, PlatformFaqArticle $article): PlatformFaqArticle
    {
        $article->load(['category', 'translations', 'related']);

        $copy = $article->getConnection()->transaction(function () use ($admin, $article): PlatformFaqArticle {
            $slug = $article->slug.'-copy';

            for ($i = 2; $this->slugExists($slug, $article->category_id); $i++) {
                $slug = $article->slug.'-copy-'.$i;
            }

            $copy = PlatformFaqArticle::query()->create([
                'category_id' => $article->category_id, 'slug' => $slug, 'status' => FaqArticle::DRAFT, 'publication' => $article->publication,
                'position' => $article->position, 'is_popular' => false, 'tags' => $article->tags, 'author_id' => $admin->id, 'last_editor_id' => $admin->id,
            ]);

            foreach ($article->translations as $translation) {
                $this->saveTranslation($admin, $copy, $translation->locale, ['question' => $translation->question, 'summary' => $translation->summary, 'body' => $translation->body, 'is_ready' => false]);
            }

            $this->syncRelated($copy, $article->related->pluck('id')->all());

            return $copy;
        });

        $this->audit($admin, 'faq.duplicated', $copy, ['from' => $article->slug, 'slug' => $copy->slug]);
        $this->catalog->flush();

        return $copy;
    }

    /** Déplace une FAQ vers une autre catégorie. @throws FaqRefused */
    public function move(PlatformAdmin $admin, PlatformFaqArticle $article, string $categoryId): void
    {
        if ($article->category_id === $categoryId) {
            return;
        }

        $this->update($admin, $article, ['category_id' => $categoryId]);
    }

    /**
     * Rétablit une version antérieure d'une traduction (devient une nouvelle version, jamais une réécriture de l'historique).
     *
     * @throws FaqRefused
     */
    public function restoreVersion(PlatformAdmin $admin, PlatformFaqArticle $article, string $versionId): void
    {
        $version = FaqVersion::query()->where('article_id', $article->id)->whereKey($versionId)->firstOrFail();

        $this->update($admin, $article, ['translations' => [$version->locale => [
            'question' => $version->question, 'summary' => $version->summary, 'body' => $version->body, 'is_ready' => $version->is_ready,
        ]]]);
    }

    /**
     * Applique la même action à plusieurs FAQ ; celles qui la refusent sont comptées « ignorées » sans bloquer les autres.
     *
     * @param  iterable<string>  $ids
     * @param  callable(PlatformFaqArticle): void  $action
     * @return array{done: int, skipped: int}
     */
    public function bulk(PlatformAdmin $admin, iterable $ids, string $name, callable $action): array
    {
        $done = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $article = PlatformFaqArticle::query()->find((string) $id);

            if ($article === null) {
                $skipped++;

                continue;
            }

            try {
                $action($article);
                $done++;
            } catch (FaqRefused) {
                $skipped++;
            }
        }

        PlatformAudit::record($admin->id, 'faq.bulk', null, 'faq_article', null, ['operation' => $name, 'done' => $done, 'skipped' => $skipped]);

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * Enregistre une langue ; renvoie ce qui a changé (null si rien). Une traduction prête doit être complète. Chaque changement de texte ou
     * d'état « prête » laisse une version.
     *
     * @param  array{question: string, summary: string, body: string, is_ready: bool}  $fields
     * @return array{locale: string, created: bool, fields: list<string>}|null
     *
     * @throws FaqRefused
     */
    private function saveTranslation(PlatformAdmin $admin, PlatformFaqArticle $article, string $locale, array $fields): ?array
    {
        $existing = PlatformFaqTranslation::query()->where('article_id', $article->id)->where('locale', $locale)->first();

        // Rien à enregistrer pour une langue vide qui n'existe pas encore.
        if ($existing === null && $fields['question'] === '' && $fields['summary'] === '' && $fields['body'] === '') {
            return null;
        }

        $candidate = new PlatformFaqTranslation([...$fields, 'article_id' => $article->id, 'locale' => $locale]);

        if ($fields['is_ready'] && ! $candidate->isComplete()) {
            throw FaqRefused::incompleteTranslation($locale);
        }

        $changed = $existing === null ? self::TRANSLATION_FIELDS : array_values(array_filter(self::TRANSLATION_FIELDS, fn (string $field): bool => $existing->{$field} !== $fields[$field]));

        if ($changed === []) {
            return null;
        }

        $translation = $existing ?? $candidate;
        $translation->fill([...$fields, 'updated_by' => $admin->id])->save();

        FaqVersion::query()->create([
            'article_id' => $article->id, 'locale' => $locale, 'question' => $fields['question'], 'summary' => $fields['summary'],
            'body' => $fields['body'], 'is_ready' => $fields['is_ready'], 'edited_by' => $admin->id,
        ]);

        return ['locale' => $locale, 'created' => $existing === null, 'fields' => $changed];
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, array{question: string, summary: string, body: string, is_ready: bool}>
     */
    private function cleanTranslations(array $raw): array
    {
        $clean = [];

        foreach (FaqCategory::LOCALES as $locale) {
            if (! isset($raw[$locale]) || ! is_array($raw[$locale])) {
                continue;
            }

            $clean[$locale] = [
                'question' => trim((string) ($raw[$locale]['question'] ?? '')),
                'summary' => trim((string) ($raw[$locale]['summary'] ?? '')),
                'body' => trim((string) ($raw[$locale]['body'] ?? '')),
                'is_ready' => (bool) ($raw[$locale]['is_ready'] ?? false),
            ];
        }

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>  $related
     */
    private function syncRelated(PlatformFaqArticle $article, array $related): void
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $related), fn (string $id): bool => $id !== $article->id)));
        $ids = PlatformFaqArticle::query()->whereIn('id', $ids)->pluck('id')->all();

        DB::connection(PlatformAudit::CONNECTION)->table('faq_article_related')->where('article_id', $article->id)->delete();

        foreach ($ids as $position => $id) {
            DB::connection(PlatformAudit::CONNECTION)->table('faq_article_related')->insert(['article_id' => $article->id, 'related_id' => $id, 'position' => $position]);
        }
    }

    /**
     * @return list<string>
     */
    private function tags(mixed $tags): array
    {
        $clean = [];

        foreach ((array) $tags as $tag) {
            $value = mb_substr(trim((string) preg_replace('/\s+/u', '-', mb_strtolower(trim((string) $tag)))), 0, 30);

            if ($value !== '') {
                $clean[$value] = $value;
            }
        }

        return array_slice(array_values($clean), 0, 12);
    }

    private function mode(mixed $mode): string
    {
        return in_array($mode, FaqArticle::MODES, true) ? (string) $mode : 'available';
    }

    private function slug(string $given, string $question, string $categoryId): string
    {
        $slug = $given !== '' ? $given : Str::slug(mb_substr($question, 0, 70));

        $this->assertSlugValid($slug);
        $this->assertSlugFree($slug, $categoryId, null);

        return $slug;
    }

    private function assertSlugValid(string $slug): void
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1 || mb_strlen($slug) > 80) {
            throw FaqRefused::slugInvalid($slug);
        }
    }

    private function assertSlugFree(string $slug, string $categoryId, ?string $ignoreId): void
    {
        if ($this->slugExists($slug, $categoryId, $ignoreId)) {
            throw FaqRefused::slugTaken($slug);
        }
    }

    private function slugExists(string $slug, string $categoryId, ?string $ignoreId = null): bool
    {
        return PlatformFaqArticle::query()->where('category_id', $categoryId)->where('slug', $slug)->when($ignoreId, fn ($q, string $id) => $q->where('id', '!=', $id))->exists();
    }

    /**
     * Ce que le journal compare avant / après (hors texte des traductions, qui a son propre historique de versions).
     *
     * @return array<string, mixed>
     */
    private function snapshot(PlatformFaqArticle $article): array
    {
        return [
            'category' => $article->category?->slug,
            'slug' => $article->slug,
            'position' => $article->position,
            'is_popular' => $article->is_popular,
            'publication' => $article->publication,
            'tags' => $article->tags,
            'related' => $article->related->map(fn (FaqArticle $other): string => ($other->category->slug ?? '?').'/'.$other->slug)->sort()->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function audit(PlatformAdmin $admin, string $action, PlatformFaqArticle $article, array $diff): void
    {
        PlatformAudit::record($admin->id, $action, null, 'faq_article', $article->id, ['slug' => $article->slug, ...$diff]);
    }
}
