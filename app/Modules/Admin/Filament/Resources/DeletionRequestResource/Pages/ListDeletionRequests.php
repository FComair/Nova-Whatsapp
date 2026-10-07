<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\DeletionRequestResource\Pages;

use App\Modules\Admin\Filament\Resources\DeletionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDeletionRequests extends ListRecords
{
    protected static string $resource = DeletionRequestResource::class;
}
