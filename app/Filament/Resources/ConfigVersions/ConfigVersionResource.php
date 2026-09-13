<?php

namespace App\Filament\Resources\ConfigVersions;

use App\Filament\Resources\ConfigVersions\Pages\CreateConfigVersion;
use App\Filament\Resources\ConfigVersions\Pages\EditConfigVersion;
use App\Filament\Resources\ConfigVersions\Pages\ListConfigVersions;
use App\Filament\Resources\ConfigVersions\Schemas\ConfigVersionForm;
use App\Filament\Resources\ConfigVersions\Tables\ConfigVersionsTable;
use App\Models\ConfigVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ConfigVersionResource extends Resource
{
    protected static ?string $model = ConfigVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ConfigVersionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConfigVersionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConfigVersions::route('/'),
            'create' => CreateConfigVersion::route('/create'),
            'edit' => EditConfigVersion::route('/{record}/edit'),
        ];
    }
}
