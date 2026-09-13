<?php

namespace App\Filament\Resources\ConfigVersions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ConfigVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('campaign.name')
                    ->label('Kampány')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Állapot')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->getMorphClass())
                    ->color(fn ($state) => match ($state->getMorphClass()) {
                        'draft' => 'gray',
                        'active' => 'success',
                        'paused' => 'warning',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('traffic_weight')
                    ->label('Súly'),
                TextColumn::make('content_hash')
                    ->label('Tartalom hash')
                    ->limit(12)
                    ->fontFamily('mono'),
                TextColumn::make('created_by')
                    ->label('Létrehozta'),
                TextColumn::make('created_at')
                    ->label('Létrehozva')
                    ->dateTime('Y-m-d H:i'),
            ])
            ->filters([
                SelectFilter::make('campaign_id')
                    ->label('Kampány')
                    ->relationship('campaign', 'name'),
                SelectFilter::make('status')
                    ->label('Állapot')
                    ->options([
                        'draft' => 'draft',
                        'active' => 'active',
                        'paused' => 'paused',
                        'archived' => 'archived',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
