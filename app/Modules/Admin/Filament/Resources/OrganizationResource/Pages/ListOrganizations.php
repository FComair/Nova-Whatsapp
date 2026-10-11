<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\OrganizationResource\Pages;

use App\Modules\Admin\Filament\Resources\OrganizationResource;
use App\Modules\Admin\Filament\Support\ClientActions;
use App\Modules\Admin\Services\ClientDirectory;
use App\Modules\Admin\Services\ClientExport;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Support\AdminAccess;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Liste des clients : chiffres d'en-tête, vues enregistrées, export (liste filtrée) ; le tableau est dans `OrganizationResource`.
 */
class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    public function getTitle(): string
    {
        return __('clients.title');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('admin.clients.metrics')->viewData(['metrics' => ClientDirectory::metrics()]),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [$this->savedViewsAction(), $this->exportAction()];
    }

    /** Export CSV de la liste telle qu'elle est filtrée : on montre d'abord combien de clients et quelles données, puis on confirme. */
    private function exportAction(): Action
    {
        return Action::make('export')->visible(fn (): bool => AdminAccess::can('clients.export'))->label(__('clients.actions.export'))->icon('heroicon-m-arrow-down-tray')->color('gray')
            ->modalHeading(__('clients.export.heading'))->modalSubmitActionLabel(__('clients.export.confirm'))
            ->modalDescription(function (): string {
                $preview = ClientExport::preview($this->getFilteredSortedTableQuery() ?? ClientDirectory::query(), ['identity']);

                return __('clients.export.description', ['count' => $preview['count']]).($preview['capped'] ? ' '.__('clients.export.capped', ['limit' => ClientExport::LIMIT]) : '');
            })
            ->schema(ClientActions::exportFields())
            ->action(fn (array $data): StreamedResponse => ClientActions::download($this->getFilteredSortedTableQuery() ?? ClientDirectory::query(), (array) ($data['groups'] ?? []), (array) ($this->tableFilters ?? []), 'filtered'));
    }

    private function savedViewsAction(): ActionGroup
    {
        $admin = ClientActions::admin();
        $views = DB::connection(PlatformAudit::CONNECTION)->table('client_saved_views')->where('admin_id', $admin->id)->orderBy('name')->get();

        $actions = $views->map(fn (object $view): Action => Action::make('saved_view_'.$view->id)->label($view->name)->icon('heroicon-m-bookmark')
            ->action(function () use ($view): void {
                $this->tableFilters = (array) json_decode((string) $view->filters, true);
                $this->handleTableFilterUpdates();
            }))->all();

        $actions[] = Action::make('save_view')->label(__('clients.views.save'))->icon('heroicon-m-plus-circle')->modalHeading(__('clients.views.save'))
            ->schema([TextInput::make('name')->label(__('clients.views.name'))->required()->maxLength(40)])
            ->action(function (array $data): void {
                DB::connection(PlatformAudit::CONNECTION)->table('client_saved_views')->updateOrInsert(
                    ['admin_id' => ClientActions::admin()->id, 'name' => trim((string) $data['name'])],
                    ['id' => (string) Str::uuid7(), 'filters' => json_encode($this->tableFilters ?? [], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()],
                );
                Notification::make()->success()->title(__('clients.views.saved'))->send();
            });

        if ($views->isNotEmpty()) {
            $actions[] = Action::make('delete_view')->label(__('clients.views.delete'))->icon('heroicon-m-trash')->color('danger')->modalHeading(__('clients.views.delete'))
                ->schema([Select::make('view')->label(__('clients.views.name'))->options($views->pluck('name', 'id')->all())->required()->native(false)])
                ->action(function (array $data): void {
                    DB::connection(PlatformAudit::CONNECTION)->table('client_saved_views')->where('admin_id', ClientActions::admin()->id)->where('id', $data['view'])->delete();
                    Notification::make()->success()->title(__('clients.views.deleted'))->send();
                });
        }

        return ActionGroup::make($actions)->label(__('clients.views.menu'))->icon('heroicon-m-bookmark')->button()->color('gray');
    }
}
