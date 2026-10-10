<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages;

use App\Modules\Admin\Filament\Resources\FaqArticleResource;
use App\Modules\Admin\Filament\Support\FaqActions;
use App\Modules\Admin\Models\PlatformFaqArticle;
use App\Modules\Admin\Services\FaqManager;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Help\Models\FaqArticle;
use App\Modules\Help\Models\FaqCategory;
use App\Modules\Help\Models\FaqVersion;
use App\Modules\Help\Support\FaqPublishing;
use App\Modules\Help\Support\FaqRefused;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Éditeur d'une FAQ : fiche et onglets par langue, état de chaque langue, aperçu, publication, copie, archivage, historique et
 * suppression. Les règles sont celles de `FaqManager` ; un refus s'affiche en message clair, sans rien perdre de la saisie.
 */
class EditFaqArticle extends EditRecord
{
    protected static string $resource = FaqArticleResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->article()->translations->sortBy(fn ($t): int => array_search($t->locale, ['fr', 'en', 'nl'], true))->first()->question ?? $this->article()->slug;
    }

    /** État de chaque langue, dit en clair : « Français prêt · Nederlands brouillon · English absent ». */
    public function getSubheading(): string|Htmlable|null
    {
        $article = $this->article();
        $parts = [];

        foreach (FaqPublishing::completeness($article) as $locale => $state) {
            $parts[] = __("faq.locales.{$locale}").' '.__("faq.language_state.{$state}");
        }

        return __("faq.statuses.{$article->status}").' · '.implode(' · ', $parts);
    }

    private function article(): PlatformFaqArticle
    {
        return PlatformFaqArticle::query()->with(['translations', 'category'])->findOrFail($this->getRecord()->getKey());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $article = $this->article()->load('related');

        $data['tags'] = $article->tags;
        $data['related'] = $article->related->pluck('id')->all();
        $data['translations'] = [];

        foreach (FaqCategory::LOCALES as $locale) {
            $t = $article->translations->firstWhere('locale', $locale);
            $data['translations'][$locale] = ['question' => $t->question ?? '', 'summary' => $t->summary ?? '', 'body' => $t->body ?? '', 'is_ready' => $t->is_ready ?? false];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(FaqManager::class)->update(FaqActions::admin(), $this->article(), $data);
        } catch (FaqRefused $refused) {
            Notification::make()->danger()->title(__('faq.errors.title'))->body(__("faq.errors.{$refused->reason}", $refused->params))->persistent()->send();

            throw new Halt;
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('faq.saved');
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        $status = fn (): string => $this->article()->status;
        $run = fn (callable $op, string $success) => function () use ($op, $success): void {
            if (FaqActions::perform(fn ($admin) => $op($admin, $this->article()), $success)) {
                $this->redirect(FaqArticleResource::getUrl('edit', ['record' => $this->getRecord()]));
            }
        };

        return [
            Action::make('preview')->label(__('faq.actions.preview'))->icon('heroicon-m-eye')->color('gray')->url(fn (): string => FaqArticleResource::getUrl('preview', ['record' => $this->getRecord()])),
            Action::make('publish')->label(__('faq.actions.publish'))->icon('heroicon-m-globe-alt')->color('success')
                ->visible(fn (): bool => $status() !== FaqArticle::PUBLISHED)
                ->requiresConfirmation()->modalHeading(__('faq.actions.publish_heading'))->modalDescription(fn (): string => FaqArticleResource::publishNote($this->getRecord()))
                ->action($run(fn ($admin, $article) => app(FaqManager::class)->publish($admin, $article), __('faq.done.published'))),
            Action::make('unpublish')->label(__('faq.actions.unpublish'))->icon('heroicon-m-eye-slash')->color('warning')
                ->visible(fn (): bool => $status() === FaqArticle::PUBLISHED)
                ->requiresConfirmation()->modalHeading(__('faq.actions.unpublish_heading'))->modalDescription(__('faq.actions.unpublish_description'))
                ->action($run(fn ($admin, $article) => app(FaqManager::class)->unpublish($admin, $article), __('faq.done.unpublished'))),
            ActionGroup::make([
                Action::make('history')->label(__('faq.actions.history'))->icon('heroicon-m-clock')
                    ->modalHeading(__('faq.history.heading'))->modalDescription(__('faq.history.description'))->modalSubmitActionLabel(__('faq.history.restore'))
                    ->schema([Select::make('version')->label(__('faq.history.version'))->required()->native(false)->options(fn (): array => $this->versionOptions())])
                    ->action(function (array $data): void {
                        if (FaqActions::perform(fn ($admin) => app(FaqManager::class)->restoreVersion($admin, $this->article(), (string) $data['version']), __('faq.history.restored'))) {
                            $this->redirect(FaqArticleResource::getUrl('edit', ['record' => $this->getRecord()]));
                        }
                    }),
                Action::make('duplicate')->label(__('faq.actions.duplicate'))->icon('heroicon-m-document-duplicate')
                    ->action(function (): void {
                        $copy = null;

                        if (FaqActions::perform(function ($admin) use (&$copy): void {
                            $copy = app(FaqManager::class)->duplicate($admin, $this->article());
                        }, __('faq.done.duplicated')) && $copy !== null) {
                            $this->redirect(FaqArticleResource::getUrl('edit', ['record' => $copy]));
                        }
                    }),
                Action::make('archive')->label(__('faq.actions.archive'))->icon('heroicon-m-archive-box')
                    ->visible(fn (): bool => $status() !== FaqArticle::ARCHIVED)
                    ->requiresConfirmation()->modalHeading(__('faq.actions.archive_heading'))->modalDescription(__('faq.actions.archive_description'))
                    ->action($run(fn ($admin, $article) => app(FaqManager::class)->archive($admin, $article), __('faq.done.archived'))),
                Action::make('restore')->label(__('faq.actions.restore'))->icon('heroicon-m-arrow-uturn-left')
                    ->visible(fn (): bool => $status() === FaqArticle::ARCHIVED)
                    ->action($run(fn ($admin, $article) => app(FaqManager::class)->restore($admin, $article), __('faq.done.restored'))),
                Action::make('remove')->label(__('faq.actions.delete'))->icon('heroicon-m-trash')->color('danger')
                    ->visible(fn (): bool => $status() !== FaqArticle::PUBLISHED)
                    ->requiresConfirmation()->modalHeading(__('faq.actions.delete_heading'))->modalDescription(__('faq.actions.delete_description'))
                    ->action(function (): void {
                        if (FaqActions::perform(fn ($admin) => app(FaqManager::class)->delete($admin, $this->article()), __('faq.done.deleted'))) {
                            $this->redirect(FaqArticleResource::getUrl());
                        }
                    }),
            ])->label(__('faq.actions.more'))->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
        ];
    }

    /**
     * Les versions enregistrées de l'article, de la plus récente à la plus ancienne.
     *
     * @return array<string, string>
     */
    private function versionOptions(): array
    {
        $editors = DB::connection(PlatformAudit::CONNECTION)->table('platform_admins')->pluck('email', 'id');

        return FaqVersion::query()->where('article_id', $this->getRecord()->getKey())->orderByDesc('created_at')->limit(50)->get()
            ->mapWithKeys(fn (FaqVersion $version): array => [$version->id => $version->created_at->format('d/m/Y H:i').' · '.strtoupper($version->locale).' · '.($editors[$version->edited_by] ?? '—').' · '.__('faq.language_state.'.($version->is_ready ? 'ready' : 'draft')).' · '.mb_strimwidth($version->question, 0, 50, '…')])->all();
    }
}
