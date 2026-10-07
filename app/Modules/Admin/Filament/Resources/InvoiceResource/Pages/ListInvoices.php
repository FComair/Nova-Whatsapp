<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\InvoiceResource\Pages;

use App\Modules\Admin\Filament\Resources\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
