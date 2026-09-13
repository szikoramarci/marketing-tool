<?php

use App\QuizConfig\ConfigSchemaValidator;
use Tests\Support\QuizConfigFixture;

it('accepts the example config', function () {
    $result = (new ConfigSchemaValidator)->validate(QuizConfigFixture::toObject(QuizConfigFixture::example()));

    expect($result->valid)->toBeTrue()
        ->and($result->errors)->toBe([]);
});

it('rejects a config missing a required top-level field', function () {
    $config = QuizConfigFixture::example();
    unset($config['locale']);

    $result = (new ConfigSchemaValidator)->validate(QuizConfigFixture::toObject($config));

    expect($result->valid)->toBeFalse();
});

it('rejects a config with an unknown status value', function () {
    $config = QuizConfigFixture::example();
    $config['status'] = 'published';

    $result = (new ConfigSchemaValidator)->validate(QuizConfigFixture::toObject($config));

    expect($result->valid)->toBeFalse()
        ->and(collect($result->errors)->pluck('pointer'))->toContain('/status');
});

it('rejects a rule expression node of an unknown type', function () {
    $config = QuizConfigFixture::example();
    $config['evaluation_groups'][0]['rule'] = ['type' => 'xor', 'operands' => []];

    $result = (new ConfigSchemaValidator)->validate(QuizConfigFixture::toObject($config));

    expect($result->valid)->toBeFalse();
});

it('rejects a non-fallback group whose rule is null', function () {
    $config = QuizConfigFixture::example();
    $config['evaluation_groups'][0]['rule'] = null;

    $result = (new ConfigSchemaValidator)->validate(QuizConfigFixture::toObject($config));

    expect($result->valid)->toBeFalse();
});

it('does not report unrelated valid properties as additional when a sibling property is invalid', function () {
    $config = QuizConfigFixture::example();
    $config['status'] = 'published';

    $result = (new ConfigSchemaValidator)->validate(QuizConfigFixture::toObject($config));

    expect($result->valid)->toBeFalse();

    foreach ($result->errors as $error) {
        expect($error->message)->not->toStartWith('Additional object properties are not allowed');
    }
});

it('accepts a group rule that references module ranking', function () {
    $config = QuizConfigFixture::example();
    $config['evaluation_groups'][0]['rule'] = [
        'type' => 'or',
        'operands' => [
            ['type' => 'module_relevant', 'module' => 'module_stress_basics'],
            ['type' => 'module_top_rank', 'module' => 'module_boundaries', 'within' => 2],
        ],
    ];

    $result = (new ConfigSchemaValidator)->validate(QuizConfigFixture::toObject($config));

    expect($result->valid)->toBeTrue();
});

it('rejects a module_top_rank node missing "within"', function () {
    $config = QuizConfigFixture::example();
    $config['evaluation_groups'][0]['rule'] = [
        'type' => 'module_top_rank',
        'module' => 'module_stress_basics',
    ];

    $result = (new ConfigSchemaValidator)->validate(QuizConfigFixture::toObject($config));

    expect($result->valid)->toBeFalse();
});

it('rejects a config with no fallback group at all', function () {
    $config = QuizConfigFixture::example();
    $config['evaluation_groups'][2]['is_fallback'] = false;
    $config['evaluation_groups'][2]['rule'] = $config['evaluation_groups'][0]['rule'];

    $result = (new ConfigSchemaValidator)->validate(QuizConfigFixture::toObject($config));

    expect($result->valid)->toBeFalse();
});
