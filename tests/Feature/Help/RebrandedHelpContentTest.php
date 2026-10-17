<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Le contenu d'aide stocké en base garde l'ancien nom jusqu'à la migration de changement de marque (docs/42).
 */
beforeEach(function (): void {
    $this->asOwner(function (): void {
        $categoryId = (string) Str::uuid7();
        $articleId = (string) Str::uuid7();
        DB::table('faq_categories')->insert(['id' => $categoryId, 'slug' => 'getting-started', 'names' => json_encode(['fr' => 'Premiers pas avec Nova']), 'descriptions' => json_encode(['en' => 'Learn Nova WhatsApp']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('faq_articles')->insert(['id' => $articleId, 'category_id' => $categoryId, 'slug' => 'first-steps', 'status' => 'published', 'tags' => json_encode(['nova', 'Nova']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('faq_translations')->insert(['id' => (string) Str::uuid7(), 'article_id' => $articleId, 'locale' => 'fr', 'question' => 'Comment démarrer avec Nova ?', 'summary' => 'Nova-whatsapp en trois étapes.', 'body' => "Bienvenue sur Nova. Nova WhatsApp répond pour vous. En-tête technique : X-Nova-Key, rôle nova_app, NOVA_CSP.", 'is_ready' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->articleId = $articleId;
    });

    $this->migrate = fn () => $this->asOwner(fn () => (require base_path('database/migrations/2026_11_23_000001_rebrand_stored_help_content.php'))->up());
});

it('remplace la marque dans les articles et catégories d\'aide, sans toucher aux identifiants techniques', function (): void {
    ($this->migrate)();

    $translation = $this->asOwner(fn () => DB::table('faq_translations')->where('article_id', $this->articleId)->first());
    $category = $this->asOwner(fn () => DB::table('faq_categories')->where('slug', 'getting-started')->first());
    $article = $this->asOwner(fn () => DB::table('faq_articles')->where('id', $this->articleId)->first());

    expect($translation->question)->toBe('Comment démarrer avec Rendivoo ?')
        ->and($translation->summary)->toBe('Rendivoo en trois étapes.')
        ->and($translation->body)->toBe('Bienvenue sur Rendivoo. Rendivoo répond pour vous. En-tête technique : X-Nova-Key, rôle nova_app, NOVA_CSP.')
        ->and(json_decode($category->names, true))->toBe(['fr' => 'Premiers pas avec Rendivoo'])
        ->and(json_decode($category->descriptions, true))->toBe(['en' => 'Learn Rendivoo'])
        ->and(json_decode($article->tags, true))->toBe(['nova', 'Rendivoo']);
});

it('peut être relancée sans effet', function (): void {
    ($this->migrate)();
    $before = $this->asOwner(fn () => DB::table('faq_translations')->where('article_id', $this->articleId)->value('body'));

    ($this->migrate)();

    expect($this->asOwner(fn () => DB::table('faq_translations')->where('article_id', $this->articleId)->value('body')))->toBe($before);
});
