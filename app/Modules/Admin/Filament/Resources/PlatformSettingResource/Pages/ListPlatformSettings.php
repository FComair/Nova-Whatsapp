<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\PlatformSettingResource\Pages;

use App\Modules\Admin\Filament\Resources\PlatformSettingResource;
use Filament\Resources\Pages\ListRecords;

class ListPlatformSettings extends ListRecords
{
    protected static string $resource = PlatformSettingResource::class;
}
