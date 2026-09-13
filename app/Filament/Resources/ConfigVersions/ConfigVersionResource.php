<?php

namespace App\Filament\Resources\ConfigVersions;

use App\Filament\Resources\ConfigVersions\Pages\CreateConfigVersion;
use App\Filament\Resources\ConfigVersions\Pages\EditConfigVersion;
use App\Filament\Resources\ConfigVersions\Pages\ListConfigVersions;
use App\Filament\Resources\ConfigVersions\Pages\PreviewEmails;
use App\Filament\Resources\ConfigVersions\Schemas\ConfigVersionForm;
use App\Filament\Resources\ConfigVersions\Tables\ConfigVersionsTable;
use App\Models\ConfigVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ConfigVersionResource extends Resource
{
    protected static ?string $model = ConfigVersion::class;

    protected static ?string $modelLabel = 'konfigverzió';

    protected static ?string $pluralModelLabel = 'konfigverziók';

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
            'preview-emails' => PreviewEmails::route('/{record}/preview-emails'),
        ];
    }

    /**
     * Replaces the record's raw `content` array (from the model's array cast — never what
     * the "Konfiguráció (JSON)" textarea should hold) with the pretty-printed JSON document
     * the form actually edits. Shared by the dedicated edit page and the campaign's
     * config-versions relation manager, which both use this same form schema.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateFormDataBeforeFill(ConfigVersion $record, array $data): array
    {
        $data['content'] = json_encode(
            $record->toConfigObject(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $data;
    }

    /**
     * Shared by the dedicated edit page and the relation manager's edit action — both wrap
     * the InvalidArgumentException in a Filament ValidationException, but each needs its own
     * message key prefix (a page's form state lives under "data.*", an action's schema does
     * not), so the key mapping is left to the caller rather than baked in here.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException invalid JSON, or a document that fails validation
     */
    public static function saveFormData(ConfigVersion $record, array $data): Model
    {
        if ($record->status->getMorphClass() === 'draft') {
            $document = json_decode($data['content']);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('Érvénytelen JSON: '.json_last_error_msg());
            }

            $record->updateDraftDocument($document);
        }

        if (array_key_exists('traffic_weight', $data)) {
            $record->traffic_weight = $data['traffic_weight'];
            $record->save();
        }

        return $record;
    }
}
