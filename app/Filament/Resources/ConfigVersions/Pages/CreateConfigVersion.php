<?php

namespace App\Filament\Resources\ConfigVersions\Pages;

use App\Filament\Resources\ConfigVersions\ConfigVersionResource;
use App\Models\Campaign;
use App\Models\ConfigVersion;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateConfigVersion extends CreateRecord
{
    protected static string $resource = ConfigVersionResource::class;

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
