<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages;

use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Services\TicketMetrics;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Support\Models\SupportTicket;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord du support (docs/29 §9) : charge de travail, délais de réponse et de résolution, répartitions. Chaque indicateur est
 * défini à l'écran. Un agent de support ne voit que les tickets de sa portée (les siens et les non assignés).
 *
 * @property-read Schema $form
 */
class SupportReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'support-reports';

    protected string $view = 'admin.support.reports';

    /** @var array<string, mixed>|null */
    public ?array $filters = [];

    public static function getNavigationLabel(): string
    {
        return __('tickets.navigation.reports');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('tickets.navigation.group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('tickets.reports.title');
    }

    public static function canAccess(): bool
    {
        return SupportAccess::canUseTickets();
    }

    public function mount(): void
    {
        $this->form->fill(['from' => now()->subDays(29)->toDateString(), 'until' => now()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->columns(['default' => 1, 'md' => 3, 'xl' => 6])->components([
            DatePicker::make('from')->label(__('tickets.filters.from'))->native(false)->displayFormat('d/m/Y')->live(),
            DatePicker::make('until')->label(__('tickets.filters.until'))->native(false)->displayFormat('d/m/Y')->live(),
            Select::make('agent')->label(__('tickets.columns.assignee'))->placeholder(__('tickets.reports.all'))->live()
                ->options(fn (): array => ['none' => __('tickets.unassigned'), ...SupportAccess::assignees()]),
            Select::make('category')->label(__('tickets.columns.category'))->placeholder(__('tickets.reports.all'))->live()
                ->options(fn (): array => collect(SupportTicket::CATEGORIES)->mapWithKeys(fn (string $category): array => [$category => __("tickets.categories.{$category}")])->all()),
            Select::make('priority')->label(__('tickets.columns.priority'))->placeholder(__('tickets.reports.all'))->live()
                ->options(fn (): array => collect(SupportTicket::PRIORITIES)->mapWithKeys(fn (string $priority): array => [$priority => __("tickets.priorities.{$priority}")])->all()),
            Select::make('organization')->label(__('tickets.columns.client'))->placeholder(__('tickets.reports.all'))->searchable()->live()
                ->getSearchResultsUsing(fn (string $search): array => DB::connection(PlatformAudit::CONNECTION)->table('organizations')->where('name', 'ilike', '%'.addcslashes($search, '\%_').'%')->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                ->getOptionLabelUsing(fn (string $value): ?string => DB::connection(PlatformAudit::CONNECTION)->table('organizations')->where('id', $value)->value('name')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        return app(TicketMetrics::class)->compute(SupportAccess::admin() ?? abort(403), $this->filters ?? []);
    }
}
