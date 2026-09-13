<?php

use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\ConfigVersions\Pages\CreateConfigVersion;
use App\Filament\Resources\ConfigVersions\Pages\EditConfigVersion;
use App\Models\Campaign;
use App\Models\ConfigVersion;
use App\Models\User;
use App\States\ConfigVersionStatus\Active;
use Livewire\Livewire;
use Tests\Support\QuizConfigFixture;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('creates a campaign', function () {
    Livewire::test(CreateCampaign::class)
        ->fillForm(['name' => 'Teszt kampány', 'slug' => 'teszt-kampany'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Campaign::where('slug', 'teszt-kampany')->exists())->toBeTrue();
});

it('creates a config version by pasting a valid JSON document', function () {
    $campaign = Campaign::factory()->create();
    $document = QuizConfigFixture::example();
    $document['status'] = 'draft';

    Livewire::test(CreateConfigVersion::class)
        ->fillForm([
            'campaign_id' => $campaign->id,
            'content' => json_encode($document),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $configVersion = ConfigVersion::where('campaign_id', $campaign->id)->sole();
    expect($configVersion->content)->not->toHaveKeys(['status', 'created_by', 'created_at', 'version_id', 'content_hash']);
});

it('shows a form error instead of crashing when the pasted JSON is invalid', function () {
    $campaign = Campaign::factory()->create();

    Livewire::test(CreateConfigVersion::class)
        ->fillForm([
            'campaign_id' => $campaign->id,
            'content' => '{"not": "a valid config"}',
        ])
        ->call('create')
        ->assertHasFormErrors(['content']);

    expect(ConfigVersion::where('campaign_id', $campaign->id)->exists())->toBeFalse();
});

it('activates a valid draft config version', function () {
    $configVersion = ConfigVersion::factory()->create();

    Livewire::test(EditConfigVersion::class, ['record' => $configVersion->id])
        ->callAction('activate');

    expect($configVersion->fresh()->status->getMorphClass())->toBe('active');
});

it('blocks activation of an invalid config version with a notification', function () {
    $configVersion = ConfigVersion::factory()->create();
    $content = $configVersion->content;
    $content['modules'][0]['threshold'] = 999;
    $configVersion->content = $content;
    $configVersion->save();

    Livewire::test(EditConfigVersion::class, ['record' => $configVersion->id])
        ->callAction('activate')
        ->assertNotified();

    expect($configVersion->fresh()->status->getMorphClass())->toBe('draft');
});

it('disables editing content once a version leaves draft, but duplication creates an editable copy', function () {
    $configVersion = ConfigVersion::factory()->create();
    $configVersion->status->transitionTo(Active::class);

    Livewire::test(EditConfigVersion::class, ['record' => $configVersion->id])
        ->assertFormFieldIsDisabled('content');

    Livewire::test(EditConfigVersion::class, ['record' => $configVersion->id])
        ->callAction('duplicate');

    $duplicate = ConfigVersion::where('id', '!=', $configVersion->id)->sole();
    expect($duplicate->status->getMorphClass())->toBe('draft')
        ->and($duplicate->content)->toBe($configVersion->content);
});
