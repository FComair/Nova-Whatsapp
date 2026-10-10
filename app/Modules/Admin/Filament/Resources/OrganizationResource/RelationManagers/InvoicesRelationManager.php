<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\OrganizationResource\RelationManagers;

use App\Modules\Admin\Services\PlatformAudit;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Factures et paiements d'un client (docs/31 §5) : lecture seule des données de facturation existantes (aucune logique dupliquée).
 * Chaque facture s'ouvre avec ses lignes, sa TVA et ses tentatives de paiement.
 */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('clients.invoices.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->heading(__('clients.invoices.title'))
            ->emptyStateHeading(__('clients.invoices.empty'))
            ->columns([
                TextColumn::make('number')->label(__('admin.invoices.number'))->searchable()->weight('semibold'),
                TextColumn::make('plan_name')->label(__('admin.invoices.plan'))->placeholder('—'),
                TextColumn::make('issued_at')->label(__('admin.invoices.issued_at'))->dateTime('d/m/Y')->sortable(),
                TextColumn::make('period_start')->label(__('clients.invoices.period'))->state(fn (Model $record): string => $record->getAttribute('period_start')->format('d/m/Y').' → '.$record->getAttribute('period_end')->format('d/m/Y')),
                TextColumn::make('total_cents')->label(__('admin.invoices.total'))->sortable()->state(fn (Model $record): string => number_format($record->getAttribute('total_cents') / 100, 2, ',', ' ').' '.$record->getAttribute('currency')),
                TextColumn::make('status')->label(__('admin.invoices.status'))->badge()->sortable()
                    ->formatStateUsing(fn (string $state): string => __("admin.invoices.statuses.{$state}"))
                    ->color(fn (string $state): string => ['paid' => 'success', 'open' => 'warning', 'void' => 'gray', 'uncollectible' => 'danger'][$state] ?? 'gray')
                    ->icon(fn (string $state): string => ['paid' => 'heroicon-m-check-circle', 'open' => 'heroicon-m-clock', 'void' => 'heroicon-m-x-circle', 'uncollectible' => 'heroicon-m-exclamation-triangle'][$state] ?? 'heroicon-m-minus-circle'),
                TextColumn::make('paid_at')->label(__('admin.invoices.paid_at'))->dateTime('d/m/Y')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.invoices.status'))->multiple()
                    ->options(fn (): array => collect(['open', 'paid', 'void', 'uncollectible'])->mapWithKeys(fn (string $s): array => [$s => __("admin.invoices.statuses.{$s}")])->all()),
                Filter::make('dates')->label(__('clients.filters.issued'))
                    ->schema([DatePicker::make('from')->label(__('clients.filters.from'))->native(false)->displayFormat('d/m/Y'), DatePicker::make('until')->label(__('clients.filters.until'))->native(false)->displayFormat('d/m/Y')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->where('issued_at', '>=', $date.' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->where('issued_at', '<=', $date.' 23:59:59'))),
                Filter::make('amount')->label(__('clients.filters.amount'))
                    ->schema([TextInput::make('min')->label(__('clients.filters.min'))->numeric()->minValue(0), TextInput::make('max')->label(__('clients.filters.max'))->numeric()->minValue(0)])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(($data['min'] ?? '') !== '' && $data['min'] !== null, fn (Builder $q) => $q->where('total_cents', '>=', (int) round((float) $data['min'] * 100)))
                        ->when(($data['max'] ?? '') !== '' && $data['max'] !== null, fn (Builder $q) => $q->where('total_cents', '<=', (int) round((float) $data['max'] * 100)))),
            ])
            ->recordActions([
                Action::make('details')->label(__('clients.invoices.details'))->icon('heroicon-m-eye')->color('gray')
                    ->modalHeading(fn (Model $record): string => __('clients.invoices.details_heading', ['number' => $record->getAttribute('number')]))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('clients.close'))
                    ->modalContent(fn (Model $record): View => view('admin.clients.invoice', $this->details($record))),
            ]);
    }

    /**
     * Lignes, TVA et tentatives de paiement d'une facture.
     *
     * @return array<string, mixed>
     */
    private function details(Model $invoice): array
    {
        $db = DB::connection(PlatformAudit::CONNECTION);

        return [
            'invoice' => $invoice,
            'lines' => $db->table('invoice_lines')->where('invoice_id', $invoice->getKey())->get(['description', 'quantity', 'unit_cents', 'total_cents'])->all(),
            'payments' => $db->table('payments')->where('invoice_id', $invoice->getKey())->orderBy('created_at')->get(['status', 'amount_cents', 'failure_code', 'created_at'])->all(),
        ];
    }
}
