<?php

namespace App\Filament\Resources\ConfigVersions\Schemas;

use App\Models\ConfigVersion;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ConfigVersionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('campaign_id')
                    ->label('Kampány')
                    ->relationship('campaign', 'name')
                    ->required()
                    ->hidden(fn (?ConfigVersion $record) => $record !== null),
                Textarea::make('content')
                    ->label('Konfiguráció (JSON)')
                    ->required()
                    ->rows(25)
                    ->columnSpanFull()
                    ->extraInputAttributes(['class' => 'font-mono text-xs'])
                    ->disabled(fn (?ConfigVersion $record) => $record !== null && $record->status->getMorphClass() !== 'draft'),
                TextInput::make('traffic_weight')
                    ->label('Forgalmi súly')
                    ->numeric()
                    ->minValue(0)
                    ->default(100)
                    ->hidden(fn (?ConfigVersion $record) => $record === null),
            ]);
    }
}
