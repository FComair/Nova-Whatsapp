<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\OrganizationResource\Pages;

use App\Modules\Admin\Filament\Resources\OrganizationResource;
use Filament\Resources\Pages\ListRecords;

class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;
}
