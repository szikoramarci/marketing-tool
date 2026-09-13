<?php

use App\QuizConfig\Validation\ConfigValidator;
use App\QuizConfig\Validation\ValidationReport;
use Tests\Support\QuizConfigFixture;

function validateConfig(array $config): ValidationReport
{
    return (new ConfigValidator)->validate(QuizConfigFixture::toObject($config));
}

function codes(ValidationReport $report): array
{
    return collect($report->issues)->pluck('code')->all();
}

it('accepts the example config with no issues', function () {
    $report = validateConfig(QuizConfigFixture::example());

    expect($report->isValid())->toBeTrue()
        ->and($report->issues)->toBe([])
        ->and($report->statistics->domainSize)->toBe(189)
        ->and($report->statistics->sampled)->toBeFalse();
});

it('flags an unreachable group', function () {
    $config = QuizConfigFixture::example();
    // module_boundaries can never be top-ranked once its own threshold is raised sky-high.
    $config['modules'][1]['threshold'] = 999;

    $report = validateConfig($config);

    expect($report->isValid())->toBeFalse()
        ->and(codes($report))->toContain('unreachable-module')
        ->and(codes($report))->toContain('unreachable-group');
});

it('flags a module that is never in the top rank', function () {
    $config = QuizConfigFixture::example();
    // module_first_step now only matches when module_stress_basics also does — and
    // stress_basics's higher score always outranks it, so first_step never reaches #1.
    $config['modules'][2]['relevance_rule'] = [
        'type' => 'and',
        'operands' => [
            $config['modules'][0]['relevance_rule'],
            $config['modules'][2]['relevance_rule'],
        ],
    ];
    $config['module_settings']['max_recommended_modules'] = 1;

    $report = validateConfig($config);

    expect($report->isValid())->toBeTrue()
        ->and(codes($report))->toContain('module-never-in-top-rank');
});

it('flags a dangling label reference in a module rule', function () {
    $config = QuizConfigFixture::example();
    $config['modules'][0]['relevance_rule']['left']['label'] = 'does_not_exist';

    $report = validateConfig($config);

    expect($report->isValid())->toBeFalse()
        ->and(codes($report))->toContain('unknown-label-reference');
});

it('flags a dangling label reference in an answer option\'s label_weights', function () {
    $config = QuizConfigFixture::example();
    $config['questions'][0]['options'][0]['label_weights']['does_not_exist'] = 1;

    $report = validateConfig($config);

    expect($report->isValid())->toBeFalse()
        ->and(codes($report))->toContain('unknown-label-reference');
});

it('flags a dangling module reference in a group rule', function () {
    $config = QuizConfigFixture::example();
    $config['evaluation_groups'][0]['rule']['module'] = 'does_not_exist';

    $report = validateConfig($config);

    expect($report->isValid())->toBeFalse()
        ->and(codes($report))->toContain('unknown-module-reference');
});

it('flags more than one fallback group', function () {
    $config = QuizConfigFixture::example();
    $config['evaluation_groups'][1]['is_fallback'] = true;
    $config['evaluation_groups'][1]['rule'] = null;

    $report = validateConfig($config);

    expect($report->isValid())->toBeFalse()
        ->and(codes($report))->toContain('multiple-fallback-groups');
});

it('flags a missing shared_block reference', function () {
    $config = QuizConfigFixture::example();
    $config['emails']['default_sequence']['steps'][2]['block_ref'] = 'does_not_exist';

    $report = validateConfig($config);

    expect($report->isValid())->toBeFalse()
        ->and(codes($report))->toContain('unknown-shared-block-reference');
});

it('flags a module with no email content configured', function () {
    $config = QuizConfigFixture::example();
    $config['modules'][0]['email_content_ref'] = 'does_not_exist';

    $report = validateConfig($config);

    expect($report->isValid())->toBeFalse()
        ->and(codes($report))->toContain('missing-module-content');
});

it('warns about a group with no result-page sections', function () {
    $config = QuizConfigFixture::example();
    $config['evaluation_groups'][0]['result_page']['sections'] = [];

    $report = validateConfig($config);

    expect($report->isValid())->toBeTrue()
        ->and(codes($report))->toContain('empty-result-sections');
});

it('flags a module id leaked into result-page text', function () {
    $config = QuizConfigFixture::example();
    $config['evaluation_groups'][0]['result_page']['summary'] .= ' module_stress_basics';

    $report = validateConfig($config);

    expect($report->isValid())->toBeFalse()
        ->and(codes($report))->toContain('module-reference-leak');
});

it('warns about a dominant group in a lopsided config', function () {
    $config = QuizConfigFixture::example();
    // Widen group_stress's rule so it also catches whatever would have gone to group_relationships.
    $config['evaluation_groups'][0]['rule'] = [
        'type' => 'or',
        'operands' => [
            ['type' => 'module_top_rank', 'module' => 'module_stress_basics', 'within' => 1],
            ['type' => 'module_top_rank', 'module' => 'module_boundaries', 'within' => 1],
        ],
    ];

    $report = validateConfig($config);

    expect(codes($report))->toContain('dominant-group');
});
