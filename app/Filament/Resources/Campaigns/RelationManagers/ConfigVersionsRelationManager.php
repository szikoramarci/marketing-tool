<?php

namespace App\Filament\Resources\Campaigns\RelationManagers;

use App\Filament\Resources\ConfigVersions\ConfigVersionResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ConfigVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'configVersions';

    protected static ?string $title = 'Konfigverziók';

    public function form(Schema $schema): Schema
    {
        return ConfigVersionResource::form($schema);
    }

    public function table(Table $table): Table
    {
        return ConfigVersionResource::table($table);
    }
}
