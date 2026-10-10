<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages;

use App\Modules\Admin\Filament\Resources\FaqArticleResource;
use App\Modules\Admin\Filament\Support\FaqActions;
use App\Modules\Admin\Models\PlatformFaqArticle;
use App\Modules\Admin\Services\FaqManager;
use App\Modules\Help\Models\FaqArticle;
use App\Modules\Help\Models\FaqCategory;
use App\Modules\Help\Models\FaqTranslation;
use App\Modules\Help\Support\FaqPublishing;
use App\Modules\Help\Support\HelpCatalog;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Aperçu d'une FAQ tel que le client la verra (docs/30 §4) : même rendu Markdown que le centre d'aide (HTML brut retiré, liens
 * dangereux refusés), une langue à la fois, avec les articles liés et l'explication claire de ce qui est visible ou non.
 */
class PreviewFaqArticle extends Page
{
    protected static string $resource = FaqArticleResource::class;

    protected string $view = 'admin.faq.preview';

    public string $articleId = '';

    public string $locale = 'fr';

    public function mount(int|string $record): void
    {
        FaqActions::admin();
        $article = PlatformFaqArticle::query()->with('translations')->findOrFail((string) $record);
        $this->articleId = $article->id;
        $this->locale = $article->translations->firstWhere('locale', 'fr') !== null ? 'fr' : ($article->translations->first()->locale ?? 'fr');
    }

    public function getTitle(): string|Htmlable
    {
        return __('faq.preview.title');
    }

    public function selectLocale(string $locale): void
    {
        $this->locale = in_array($locale, FaqCategory::LOCALES, true) ? $locale : $this->locale;
    }

    public function article(): PlatformFaqArticle
    {
        return PlatformFaqArticle::query()->with(['category', 'translations', 'related.category', 'related.translations'])->findOrFail($this->articleId);
    }

    /**
     * Ce que verra le client dans la langue choisie, et pourquoi il peut ne rien voir.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $article = $this->article();
        /** @var FaqTranslation|null $translation */
        $translation = $article->translations->firstWhere('locale', $this->locale);
        $rendered = $translation === null ? ['html' => '', 'headings' => []] : app(HelpCatalog::class)->renderMarkdown($translation->body);
        $state = FaqPublishing::completeness($article)[$this->locale];

        $visibility = match (true) {
            $article->status !== FaqArticle::PUBLISHED => 'hidden_status',
            $translation === null || ! $translation->is_ready => 'hidden_translation',
            $article->publication === 'all' && count($article->readyLocales()) < 3 => 'hidden_mode',
            default => 'visible',
        };

        return [
            'article' => $article,
            'translation' => $translation,
            'state' => $state,
            'visibility' => $visibility,
            'html' => $rendered['html'],
            'headings' => $rendered['headings'],
            'category' => $article->category->name($this->locale),
            'related' => $article->related->filter(fn (FaqArticle $other): bool => $other->translations->firstWhere('locale', $this->locale) !== null)->map(fn (FaqArticle $other): string => (string) $other->translations->firstWhere('locale', $this->locale)?->question)->values()->all(),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $article = fn (): PlatformFaqArticle => PlatformFaqArticle::query()->findOrFail($this->articleId);

        return [
            Action::make('edit')->label(__('faq.actions.edit'))->icon('heroicon-m-pencil-square')->color('gray')->url(fn (): string => FaqArticleResource::getUrl('edit', ['record' => $this->articleId])),
            Action::make('publish')->label(__('faq.actions.publish'))->icon('heroicon-m-globe-alt')->color('success')
                ->visible(fn (): bool => $article()->status !== FaqArticle::PUBLISHED)
                ->requiresConfirmation()->modalHeading(__('faq.actions.publish_heading'))->modalDescription(fn (): string => FaqArticleResource::publishNote($article()))
                ->action(fn () => FaqActions::perform(fn ($admin) => app(FaqManager::class)->publish($admin, $article()), __('faq.done.published'))),
            Action::make('unpublish')->label(__('faq.actions.unpublish'))->icon('heroicon-m-eye-slash')->color('warning')
                ->visible(fn (): bool => $article()->status === FaqArticle::PUBLISHED)
                ->requiresConfirmation()->modalHeading(__('faq.actions.unpublish_heading'))->modalDescription(__('faq.actions.unpublish_description'))
                ->action(fn () => FaqActions::perform(fn ($admin) => app(FaqManager::class)->unpublish($admin, $article()), __('faq.done.unpublished'))),
        ];
    }
}
