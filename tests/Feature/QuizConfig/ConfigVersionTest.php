<?php

use App\Models\Campaign;
use App\Models\ConfigVersion;
use App\QuizConfig\ConfigSchemaValidator;
use App\QuizConfig\ContentHash;
use App\QuizConfig\Validation\ConfigVersionNotValidException;
use App\States\ConfigVersionStatus\Active;
use App\States\ConfigVersionStatus\Archived;
use App\States\ConfigVersionStatus\Paused;
use Tests\Support\QuizConfigFixture;

it('creates a draft version from a candidate document, splitting content from metadata', function () {
    $campaign = Campaign::factory()->create();
    $document = QuizConfigFixture::toObject(QuizConfigFixture::example());
    $document->status = 'draft';

    $configVersion = ConfigVersion::createFromDocument($campaign, $document, 'admin@example.com');

    expect($configVersion->campaign_id)->toBe($campaign->id)
        ->and($configVersion->status->getMorphClass())->toBe('draft')
        ->and($configVersion->created_by)->toBe('admin@example.com')
        ->and($configVersion->content)->not->toHaveKeys(['status', 'created_by', 'created_at', 'version_id', 'content_hash'])
        ->and($configVersion->content_hash)->toBe(ContentHash::compute($configVersion->content));
});

it('rejects creating a version whose document status is not draft', function () {
    $campaign = Campaign::factory()->create();
    $document = QuizConfigFixture::toObject(QuizConfigFixture::example());
    $document->status = 'active';

    ConfigVersion::createFromDocument($campaign, $document, 'admin@example.com');
})->throws(InvalidArgumentException::class);

it('reassembles a document that the schema validator accepts', function () {
    $configVersion = ConfigVersion::factory()->create();

    $result = (new ConfigSchemaValidator)->validate($configVersion->toConfigObject());

    expect($result->valid)->toBeTrue();
});

it('computes a stable content hash regardless of key order', function () {
    $a = ['b' => 1, 'a' => ['y' => 2, 'x' => 1]];
    $b = ['a' => ['x' => 1, 'y' => 2], 'b' => 1];

    expect(ContentHash::compute($a))->toBe(ContentHash::compute($b));
});

it('activates a valid draft config version', function () {
    $configVersion = ConfigVersion::factory()->create();

    $configVersion->status->transitionTo(Active::class);

    expect($configVersion->fresh()->status->getMorphClass())->toBe('active');
});

it('blocks activation of an invalid config version and leaves status unchanged', function () {
    $configVersion = ConfigVersion::factory()->create();

    $content = $configVersion->content;
    $content['modules'][0]['threshold'] = 999; // never relevant, never reachable
    $configVersion->content = $content;
    $configVersion->save();

    try {
        $configVersion->status->transitionTo(Active::class);
        $this->fail('Expected ConfigVersionNotValidException was not thrown.');
    } catch (ConfigVersionNotValidException $exception) {
        expect($exception->report->isValid())->toBeFalse();
    }

    expect($configVersion->fresh()->status->getMorphClass())->toBe('draft');
});

it('follows the full status transition table', function () {
    $configVersion = ConfigVersion::factory()->create();

    $configVersion->status->transitionTo(Active::class);
    expect($configVersion->fresh()->status->getMorphClass())->toBe('active');

    $configVersion->status->transitionTo(Paused::class);
    expect($configVersion->fresh()->status->getMorphClass())->toBe('paused');

    $configVersion->status->transitionTo(Active::class);
    expect($configVersion->fresh()->status->getMorphClass())->toBe('active');

    $configVersion->status->transitionTo(Archived::class);
    expect($configVersion->fresh()->status->getMorphClass())->toBe('archived');
});

it('does not allow any transition out of archived', function () {
    $configVersion = ConfigVersion::factory()->create();
    $configVersion->status->transitionTo(Active::class);
    $configVersion->status->transitionTo(Archived::class);

    expect($configVersion->fresh()->status->transitionableStates())->toBe([]);
});
