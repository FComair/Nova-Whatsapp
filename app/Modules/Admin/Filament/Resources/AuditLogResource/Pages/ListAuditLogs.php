<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\AuditLogResource\Pages;

use App\Modules\Admin\Filament\Resources\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;
}
