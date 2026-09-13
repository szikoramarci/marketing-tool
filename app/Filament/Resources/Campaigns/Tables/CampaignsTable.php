<?php

namespace App\Filament\Resources\Campaigns\Tables;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Név')
                    ->searchable(),
                TextColumn::make('slug')
                    ->label('Azonosító (URL)'),
                TextColumn::make('config_versions_count')
                    ->label('Konfigverziók')
                    ->counts('configVersions'),
                TextColumn::make('created_at')
                    ->label('Létrehozva')
                    ->dateTime('Y-m-d H:i'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Kvíz megnyitása')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => route('quiz.start', $record))
                    ->openUrlInNewTab(),
                EditAction::make(),
            ]);
        // No delete: a campaign cascades to its config versions, sessions, and events —
        // destructive enough that it shouldn't be a casual table action. If a campaign
        // genuinely needs retiring, pause/archive its config versions instead.
    }
}
