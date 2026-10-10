<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages;

use App\Modules\Admin\Filament\Resources\FaqArticleResource;
use App\Modules\Admin\Filament\Support\FaqActions;
use App\Modules\Admin\Services\FaqManager;
use App\Modules\Help\Support\FaqRefused;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Nouvelle FAQ : toujours créée en brouillon ; la publication est une action séparée, après relecture et aperçu.
 */
class CreateFaqArticle extends CreateRecord
{
    protected static string $resource = FaqArticleResource::class;

    public function getTitle(): string
    {
        return __('faq.actions.create');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(FaqManager::class)->create(FaqActions::admin(), $data);
        } catch (FaqRefused $refused) {
            Notification::make()->danger()->title(__('faq.errors.title'))->body(__("faq.errors.{$refused->reason}", $refused->params))->persistent()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return FaqArticleResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('faq.done.created');
    }
}
