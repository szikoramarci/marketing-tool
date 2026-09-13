<?php

use App\Filament\Resources\ConfigVersions\Pages\CreateConfigVersion;
use App\Models\Campaign;
use App\Models\ConfigVersion;
use App\Models\User;
use App\QuizConfig\ConfigSchemaValidator;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('generates a candidate config from source material and instructions', function () {
    $test = Livewire::test(CreateConfigVersion::class)
        ->callAction('generate', data: [
            'source_material' => 'A stresszkezelésről szóló rövid összefoglaló szöveg, ami a kvíz alapja lesz.',
            'instructions' => 'Készíts egy egyszerű kvízt stresszkezelés témában.',
            'source_reference' => 'teszt forrás',
        ]);

    $content = $test->get('data.content');
    $document = json_decode($content);

    expect($document)->not->toBeNull()
        ->and($document->generation_trace->source_reference)->toBe('teszt forrás')
        ->and($document->generation_trace->instructions)->toBe('Készíts egy egyszerű kvízt stresszkezelés témában.');

    $validation = (new ConfigSchemaValidator)->validate($document);
    expect($validation->valid)->toBeTrue();
});

it('still allows a normal create after generating', function () {
    $campaign = Campaign::factory()->create();

    Livewire::test(CreateConfigVersion::class)
        ->callAction('generate', data: [
            'source_material' => 'Forrás szöveg a kvízhez.',
            'instructions' => 'Rövid instrukció.',
            'source_reference' => 'forrás',
        ])
        ->set('data.campaign_id', $campaign->id)
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ConfigVersion::where('campaign_id', $campaign->id)->exists())->toBeTrue();
});
