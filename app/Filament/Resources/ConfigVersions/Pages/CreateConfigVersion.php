<?php

namespace App\Filament\Resources\ConfigVersions\Pages;

use App\Contracts\FeedbackGenerator;
use App\Contracts\GenerationRequest;
use App\Filament\Resources\ConfigVersions\ConfigVersionResource;
use App\Models\Campaign;
use App\Models\ConfigVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class CreateConfigVersion extends CreateRecord
{
    protected static string $resource = ConfigVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generálás forrásból')
                ->color('gray')
                ->schema([
                    Textarea::make('source_material')
                        ->label('Forrásanyag')
                        ->required()
                        ->rows(10),
                    Textarea::make('instructions')
                        ->label('Kísérő utasítás')
                        ->required()
                        ->rows(4),
                    TextInput::make('source_reference')
                        ->label('Forrás megnevezése')
                        ->required(),
                ])
                ->action(function (array $data, FeedbackGenerator $generator) {
                    try {
                        $result = $generator->generate(new GenerationRequest(
                            sourceMaterial: $data['source_material'],
                            instructions: $data['instructions'],
                            sourceReference: $data['source_reference'],
                        ));
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Generálás sikertelen')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $document = json_decode($result->documentJson);
                    $pretty = $document !== null
                        ? json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : $result->documentJson;

                    $this->form->fillPartially(['content' => $pretty], ['content']);

                    Notification::make()
                        ->title('Konfiguráció generálva — ellenőrizd mentés előtt')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $document = json_decode($data['content']);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages([
                'data.content' => 'Érvénytelen JSON: '.json_last_error_msg(),
            ]);
        }

        // New versions always start as drafts, regardless of what the pasted document says.
        $document->status = 'draft';

        $campaign = Campaign::findOrFail($data['campaign_id']);

        try {
            return ConfigVersion::createFromDocument($campaign, $document, Auth::user()->email);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'data.content' => $exception->getMessage(),
            ]);
        }
    }
}
