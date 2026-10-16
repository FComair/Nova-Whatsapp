<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages;

use App\Modules\Admin\Dashboard\DashboardMetrics;
use App\Modules\Admin\Dashboard\DashboardPeriod;
use App\Modules\Admin\Filament\Resources\AuditLogResource;
use App\Modules\Admin\Filament\Resources\FaqArticleResource;
use App\Modules\Admin\Filament\Resources\FeedbackResource;
use App\Modules\Admin\Filament\Resources\InvoiceResource;
use App\Modules\Admin\Filament\Resources\OrganizationResource;
use App\Modules\Admin\Filament\Resources\PlatformSettingResource;
use App\Modules\Admin\Filament\Resources\SupportAgentResource;
use App\Modules\Admin\Filament\Resources\SupportTicketResource;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Admin\Support\SupportAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Tableau de bord du Super Admin (docs/33) : vue d'ensemble de la santé de l'activité (chiffres clés, revenus et abonnements, clients,
 * usage du produit, support, alertes, actions rapides et activité récente), filtrable par période et comparée à la période précédente
 * équivalente. Un agent de support arrive directement sur les tickets, un éditeur de contenu sur les FAQ : ni l'un ni l'autre ne voit
 * ces chiffres (comptes, facturation).
 *
 * @property-read Schema $form
 */
class AdminDashboard extends Dashboard
{
    protected string $view = 'admin.dashboard.page';

    /** @var array<string, mixed>|null */
    public ?array $filters = [];

    public function mount(): void
    {
        if (SupportAccess::isAgent()) {
            $this->redirect(SupportTicketResource::getUrl());

            return;
        }

        if (SupportAccess::isEditor()) {
            $this->redirect(FaqArticleResource::getUrl());

            return;
        }

        $this->form->fill(['preset' => 'this_month', 'from' => null, 'until' => null]);
    }

    public function getTitle(): string|Htmlable
    {
        return __('dashboard.title');
    }

    public function getHeading(): string|Htmlable
    {
        $admin = SupportAccess::admin();

        return __('dashboard.welcome', ['name' => $admin?->getFilamentName() ?? '']);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('dashboard.today', ['date' => now()->format('d/m/Y')]);
    }

    /** Les chiffres (comptes, facturation) sont réservés au Super Admin. */
    public function isRestricted(): bool
    {
        return SupportAccess::admin() === null || SupportAccess::isRestricted() || ! AdminAccess::can('dashboard.view');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->columns(['default' => 1, 'sm' => 2, 'lg' => 4])->components([
            Select::make('preset')->label(__('dashboard.period.label'))->native()->selectablePlaceholder(false)->live()
                ->options(collect(DashboardPeriod::PRESETS)->mapWithKeys(fn (string $preset): array => [$preset => __("dashboard.period.{$preset}")])->all()),
            DatePicker::make('from')->label(__('dashboard.period.from'))->native(false)->displayFormat('d/m/Y')->maxDate(now())->live()->visible(fn (callable $get): bool => $get('preset') === 'custom'),
            DatePicker::make('until')->label(__('dashboard.period.until'))->native(false)->displayFormat('d/m/Y')->maxDate(now())->live()->visible(fn (callable $get): bool => $get('preset') === 'custom'),
        ]);
    }

    public function period(): DashboardPeriod
    {
        $preset = (string) ($this->filters['preset'] ?? 'this_month');

        return DashboardPeriod::make(in_array($preset, DashboardPeriod::PRESETS, true) ? $preset : 'this_month', $this->filters['from'] ?? null, $this->filters['until'] ?? null);
    }

    /**
     * Chiffres du tableau de bord (mis en cache brièvement ; « Actualiser » les recalcule). Renvoie `null` en cas d'erreur de calcul : la vue
     * affiche alors un message et un bouton pour réessayer, jamais une page cassée.
     *
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        $admin = SupportAccess::admin();

        if ($admin === null || $this->isRestricted()) {
            return null;
        }

        $period = $this->period();
        $key = 'admin-dashboard:'.$admin->id.':'.$period->preset.':'.$period->from->toDateString().':'.$period->until->toDateString();

        try {
            return Cache::remember($key, (int) config('nova.dashboard_cache_seconds', 120), fn (): array => app(DashboardMetrics::class)->compute($admin, $period));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        if ($this->isRestricted()) {
            return [];
        }

        return [
            Action::make('refresh')->label(__('dashboard.refresh'))->icon('heroicon-m-arrow-path')->color('gray')->action(function (): void {
                $this->forget();
                Notification::make()->success()->title(__('dashboard.refreshed'))->send();
            }),
            Action::make('print')->label(__('dashboard.print'))->icon('heroicon-m-printer')->color('gray')->alpineClickHandler('window.print()'),
        ];
    }

    /** Oublie le calcul en cache de la période affichée. */
    public function forget(): void
    {
        $admin = SupportAccess::admin();

        if ($admin === null) {
            return;
        }

        $period = $this->period();
        Cache::forget('admin-dashboard:'.$admin->id.':'.$period->preset.':'.$period->from->toDateString().':'.$period->until->toDateString());
    }

    /**
     * Adresse d'une destination du tableau de bord : une liste (avec filtres ou onglet) ou une fiche.
     *
     * @param  array{target: string, query?: array<string, mixed>, id?: string}  $link
     */
    public function url(array $link): string
    {
        $query = (array) ($link['query'] ?? []);
        $id = (string) ($link['id'] ?? '');

        return match ($link['target']) {
            'clients' => OrganizationResource::getUrl('index', $query),
            'client' => OrganizationResource::getUrl('view', ['record' => $id]),
            'tickets' => SupportTicketResource::getUrl('index', $query),
            'ticket' => SupportTicketResource::getUrl('view', ['record' => $id]),
            'invoices' => InvoiceResource::getUrl('index', $query),
            'client_reports' => ClientReports::getUrl(),
            'support_reports' => SupportReports::getUrl(),
            'feedback' => FeedbackResource::getUrl('index', $query),
            'settings' => PlatformSettingResource::getUrl('index', $query),
            'team' => SupportAgentResource::getUrl('index', $query),
            'audit' => AuditLogResource::getUrl('index', $query),
            default => static::getUrl(),
        };
    }

    public function formatDate(string $iso): string
    {
        return Carbon::parse($iso)->timezone((string) config('app.timezone'))->format('d/m/Y H:i T');
    }
}
