<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages;

use App\Modules\Admin\Filament\Resources\FaqArticleResource;
use App\Modules\Admin\Models\PlatformFaqArticle;
use App\Modules\Help\Models\FaqArticle;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Liste des FAQ : vues par état (en cours, publiées, brouillons, archivées, toutes) avec leur compteur.
 */
class ListFaqArticles extends ListRecords
{
    protected static string $resource = FaqArticleResource::class;

    public function getTitle(): string
    {
        return __('faq.plural');
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $views = [
            'active' => [[FaqArticle::DRAFT, FaqArticle::PUBLISHED], 'faq.views.active'],
            'published' => [[FaqArticle::PUBLISHED], 'faq.views.published'],
            'draft' => [[FaqArticle::DRAFT], 'faq.views.draft'],
            'archived' => [[FaqArticle::ARCHIVED], 'faq.views.archived'],
            'all' => [FaqArticle::STATUSES, 'faq.views.all'],
        ];

        $tabs = [];

        foreach ($views as $key => [$statuses, $label]) {
            $tabs[$key] = Tab::make(__($label))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('faq_articles.status', $statuses))
                ->badge(PlatformFaqArticle::query()->whereIn('status', $statuses)->count())
                ->badgeColor('gray');
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('faq.actions.create'))->icon('heroicon-m-plus')];
    }
}
