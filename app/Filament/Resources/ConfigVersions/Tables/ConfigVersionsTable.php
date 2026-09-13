<?php

namespace App\Filament\Resources\ConfigVersions\Tables;

use App\Filament\Resources\ConfigVersions\ConfigVersionResource;
use App\Models\ConfigVersion;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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
                EditAction::make()
                    // Default EditAction fills straight from attributesToArray(), which
                    // would hand the "content" textarea the raw array cast (renders as
                    // "[object Object]" client-side) and, on save, call $record->update()
                    // directly — bypassing updateDraftDocument()'s hash recompute and the
                    // draft-only guard entirely. Same handling as the dedicated edit page.
                    ->mutateRecordDataUsing(fn (array $data, ConfigVersion $record): array => ConfigVersionResource::mutateFormDataBeforeFill($record, $data))
                    ->using(function (ConfigVersion $record, array $data, HasActions&HasSchemas $livewire): Model {
                        try {
                            return ConfigVersionResource::saveFormData($record, $data);
                        } catch (InvalidArgumentException $exception) {
                            // A table action's schema state path isn't "data.*" like a page's
                            // form — it's wherever this particular mounted action instance
                            // lives (e.g. "mountedActions.0.data"), so it has to be read off
                            // the live schema rather than assumed.
                            $schemaName = $livewire->getMountedActionSchemaName();
                            $statePath = $livewire->{$schemaName}->getStatePath();

                            throw ValidationException::withMessages([
                                "{$statePath}.content" => $exception->getMessage(),
                            ]);
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
