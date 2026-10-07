<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Modules\Admin\Models\PlatformInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Factures de toutes les organisations (docs/02 §3.1, « Facturation »), en lecture seule : numéro, formule facturée, dates, montants, statut.
 * L'identité du client figée sur la facture n'est pas affichée ici. Les remboursements arriveront avec le prestataire de paiement.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = PlatformInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('admin.invoices.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.invoices.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.invoices.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('admin.invoices.number'))->searchable(),
                TextColumn::make('org_id')->label(__('admin.invoices.organization'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plan_name')->label(__('admin.invoices.plan'))->badge()->color('info')->placeholder('—')->searchable()->sortable(),
                TextColumn::make('issued_at')->label(__('admin.invoices.issued_at'))->dateTime()->sortable(),
                TextColumn::make('total_cents')->label(__('admin.invoices.total'))->formatStateUsing(fn (int $state): string => number_format($state / 100, 2, ',', ' ').' €')->sortable(),
                TextColumn::make('status')->label(__('admin.invoices.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("admin.invoices.statuses.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'open' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('paid_at')->label(__('admin.invoices.paid_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.invoices.status'))->options(fn (): array => collect(['open', 'paid', 'void', 'uncollectible'])->mapWithKeys(fn (string $s): array => [$s => __("admin.invoices.statuses.{$s}")])->all()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/')];
    }
}
