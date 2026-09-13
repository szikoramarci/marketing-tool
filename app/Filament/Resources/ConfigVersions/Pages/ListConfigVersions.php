<?php

namespace App\Filament\Resources\ConfigVersions\Pages;

use App\Filament\Resources\ConfigVersions\ConfigVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConfigVersions extends ListRecords
{
    protected static string $resource = ConfigVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
