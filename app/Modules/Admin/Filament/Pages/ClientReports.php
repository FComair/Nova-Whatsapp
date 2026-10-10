<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages;

use App\Modules\Admin\Filament\Support\ClientActions;
use App\Modules\Admin\Services\ClientDirectory;
use App\Modules\Admin\Services\ClientReport;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Support\SupportAccess;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

/**
 * Rapports clients (docs/31 §11) : formules, états d'abonnement, nouveaux clients, essais convertis, renouvellements, paiements refusés,
 * activité, tickets par client. Réservés au Super Admin ; chaque indicateur est défini sur la page.
 *
 * @property-read Schema $form
 */
class ClientReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'client-reports';

    protected string $view = 'admin.clients.reports';

    /** @var array<string, mixed>|null */
    public ?array $filters = [];

    public static function getNavigationLabel(): string
    {
        return __('clients.reports.navigation');
    }

    public function getTitle(): string|Htmlable
    {
        return __('clients.reports.title');
    }

    public static function canAccess(): bool
    {
        return SupportAccess::admin() !== null && ! SupportAccess::isRestricted();
    }

    public function mount(): void
    {
        $this->form->fill(['from' => now()->subMonths(11)->startOfMonth()->toDateString(), 'until' => now()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->columns(['default' => 1, 'md' => 3, 'xl' => 5])->components([
            DatePicker::make('from')->label(__('clients.filters.from'))->native(false)->displayFormat('d/m/Y')->live(),
            DatePicker::make('until')->label(__('clients.filters.until'))->native(false)->displayFormat('d/m/Y')->live(),
            Select::make('plan')->label(__('clients.columns.plan'))->placeholder(__('clients.reports.all'))->native(false)->live()
                ->options(fn (): array => DB::connection(PlatformAudit::CONNECTION)->table('plans')->orderBy('position')->pluck('name', 'id')->all()),
            Select::make('state')->label(__('clients.columns.subscription'))->placeholder(__('clients.reports.all'))->native(false)->live()
                ->options(fn (): array => collect(ClientDirectory::STATES)->mapWithKeys(fn (string $state): array => [$state => __("clients.states.{$state}")])->all()),
            Select::make('country')->label(__('clients.columns.country'))->placeholder(__('clients.reports.all'))->native(false)->live()
                ->options(fn (): array => DB::connection(PlatformAudit::CONNECTION)->table('organizations')->whereNull('deleted_at')->distinct()->orderBy('country')->pluck('country', 'country')->all()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        ClientActions::admin();

        return app(ClientReport::class)->compute($this->filters ?? []);
    }
}
