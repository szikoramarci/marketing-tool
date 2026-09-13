<?php

namespace App\Filament\Resources\ConfigVersions\Pages;

use App\Filament\Resources\ConfigVersions\ConfigVersionResource;
use App\QuizConfig\EmailPreview;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class PreviewEmails extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ConfigVersionResource::class;

    protected string $view = 'filament.resources.config-versions.pages.preview-emails';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Emailek előnézete';
    }

    /**
     * @return array{sequences: array<int, array>, default_sequence: array, modules: array<int, array>}
     */
    public function getPreview(): array
    {
        return EmailPreview::resolve($this->getRecord()->toConfigObject());
    }
}
