<?php

namespace App\Filament\Resources\ConfigVersions\Pages;

use App\Filament\Resources\ConfigVersions\ConfigVersionResource;
use App\QuizConfig\Validation\ConfigValidator;
use App\QuizConfig\Validation\ConfigVersionNotValidException;
use App\States\ConfigVersionStatus\Active;
use App\States\ConfigVersionStatus\Archived;
use App\States\ConfigVersionStatus\Paused;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditConfigVersion extends EditRecord
{
    protected static string $resource = ConfigVersionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ConfigVersionResource::mutateFormDataBeforeFill($this->getRecord(), $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return ConfigVersionResource::saveFormData($record, $data);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'data.content' => $exception->getMessage(),
            ]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('validate')
                ->label('Validálás')
                ->color('gray')
                ->action(function () {
                    $report = (new ConfigValidator)->validate($this->getRecord()->toConfigObject());

                    if ($report->isValid() && $report->warnings() === []) {
                        Notification::make()->title('Érvényes, nincs figyelmeztetés')->success()->send();

                        return;
                    }

                    $lines = array_map(
                        fn ($issue) => "[{$issue->severity->value}] {$issue->message}",
                        $report->issues,
                    );

                    Notification::make()
                        ->title($report->isValid() ? 'Érvényes, figyelmeztetésekkel' : 'Érvénytelen')
                        ->body(implode("\n", $lines))
                        ->color($report->isValid() ? 'warning' : 'danger')
                        ->persistent()
                        ->send();
                }),
            Action::make('activate')
                ->label('Aktiválás')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn () => in_array($this->getRecord()->status->getMorphClass(), ['draft', 'paused'], true))
                ->action(function () {
                    try {
                        $this->getRecord()->status->transitionTo(Active::class);
                        Notification::make()->title('Aktiválva')->success()->send();
                    } catch (ConfigVersionNotValidException $exception) {
                        $lines = array_map(fn ($issue) => $issue->message, $exception->report->errors());

                        Notification::make()
                            ->title('Nem aktiválható')
                            ->body(implode("\n", $lines))
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
            Action::make('pause')
                ->label('Szüneteltetés')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn () => $this->getRecord()->status->getMorphClass() === 'active')
                ->action(function () {
                    $this->getRecord()->status->transitionTo(Paused::class);
                    Notification::make()->title('Szüneteltetve')->success()->send();
                }),
            Action::make('archive')
                ->label('Archiválás')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn () => in_array($this->getRecord()->status->getMorphClass(), ['active', 'paused'], true))
                ->action(function () {
                    $this->getRecord()->status->transitionTo(Archived::class);
                    Notification::make()->title('Archiválva')->success()->send();
                }),
            Action::make('preview')
                ->label('Előnézet')
                ->color('gray')
                ->url(fn () => route('quiz.preview', $this->getRecord()))
                ->openUrlInNewTab(),
            Action::make('previewEmails')
                ->label('Emailek előnézete')
                ->color('gray')
                ->url(fn () => ConfigVersionResource::getUrl('preview-emails', ['record' => $this->getRecord()])),
            Action::make('duplicate')
                ->label('Másolat új piszkozatként')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function () {
                    $clone = $this->getRecord()->cloneAsNewDraft(Auth::user()->email);

                    $this->redirect(ConfigVersionResource::getUrl('edit', ['record' => $clone]));
                }),
            DeleteAction::make(),
        ];
    }
}
