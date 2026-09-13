<?php

use App\QuizConfig\Engine\AnswerVector;
use App\QuizConfig\Engine\EvaluationEngine;
use App\QuizConfig\Engine\EvaluationResult;
use Tests\Support\QuizConfigFixture;

function evaluateExample(array $answers, ?array $configOverride = null): EvaluationResult
{
    $config = $configOverride ?? QuizConfigFixture::example();

    return (new EvaluationEngine)->evaluate(QuizConfigFixture::toObject($config), new AnswerVector($answers));
}

it('computes label sums from the selected options, including multi-select questions', function () {
    $result = evaluateExample([
        'q1' => 'q1_o1',
        'q2' => 'q2_o3',
        'q3' => 'q3_o3',
        'q4' => ['q4_o2'],
    ]);

    expect($result->labelSums)->toBe([
        'content_a' => 5.0,
        'readiness_low' => 1.0,
        'readiness_high' => 6.0,
    ]);
});

it('matches the stress group when module_stress_basics ranks first', function () {
    $result = evaluateExample([
        'q1' => 'q1_o1',
        'q2' => 'q2_o3',
        'q3' => 'q3_o3',
        'q4' => ['q4_o2'],
    ]);

    expect($result->matchedGroupIds)->toBe(['group_stress']);

    $top = collect($result->rankedModules)->firstWhere('rank', 1);
    expect($top->moduleId)->toBe('module_stress_basics');
});

it('matches the relationships group when module_boundaries ranks first', function () {
    $result = evaluateExample([
        'q1' => 'q1_o2',
        'q2' => 'q2_o1',
        'q3' => 'q3_o1',
        'q4' => [],
    ]);

    expect($result->matchedGroupIds)->toBe(['group_relationships']);

    $top = collect($result->rankedModules)->firstWhere('rank', 1);
    expect($top->moduleId)->toBe('module_boundaries');
});

it('falls back when no module clears its threshold', function () {
    $result = evaluateExample([
        'q1' => 'q1_o3',
        'q2' => 'q2_o1',
        'q3' => 'q3_o1',
        'q4' => [],
    ]);

    expect($result->matchedGroupIds)->toBe(['group_fallback'])
        ->and(collect($result->rankedModules)->filter->relevant)->toBeEmpty();
});

it('excludes other matched groups when the primary group is not allow_multiple', function () {
    $config = QuizConfigFixture::example();
    $config['evaluation_groups'][1]['rule'] = ['type' => 'module_top_rank', 'module' => 'module_boundaries', 'within' => 2];

    $answers = ['q1' => 'q1_o3', 'q2' => 'q2_o1', 'q3' => 'q3_o1', 'q4' => ['q4_o1', 'q4_o2']];

    $result = evaluateExample($answers, $config);

    // Sanity: both groups' rules do match this answer set.
    expect($result->matchedGroupIds)->toBe(['group_stress']);
});

it('includes matched groups up to max_classifications when the primary group is allow_multiple', function () {
    $config = QuizConfigFixture::example();
    $config['evaluation_groups'][0]['allow_multiple'] = true;
    $config['evaluation_groups'][0]['max_classifications'] = 2;
    $config['evaluation_groups'][1]['rule'] = ['type' => 'module_top_rank', 'module' => 'module_boundaries', 'within' => 2];

    $answers = ['q1' => 'q1_o3', 'q2' => 'q2_o1', 'q3' => 'q3_o1', 'q4' => ['q4_o1', 'q4_o2']];

    $result = evaluateExample($answers, $config);

    expect($result->matchedGroupIds)->toBe(['group_stress', 'group_relationships']);

    $config['evaluation_groups'][0]['max_classifications'] = 1;
    $capped = evaluateExample($answers, $config);

    expect($capped->matchedGroupIds)->toBe(['group_stress']);
});

it('breaks relevance_score ties by declared module order', function () {
    $config = QuizConfigFixture::example();
    $config['modules'][1]['relevance_score'] = $config['modules'][0]['relevance_score'];

    $answers = ['q1' => 'q1_o3', 'q2' => 'q2_o1', 'q3' => 'q3_o1', 'q4' => ['q4_o1', 'q4_o2']];

    $result = evaluateExample($answers, $config);

    $byId = collect($result->rankedModules)->keyBy('moduleId');

    expect($byId['module_stress_basics']->rank)->toBe(1)
        ->and($byId['module_boundaries']->rank)->toBe(2);
});

it('leaves no module claiming a rank beyond the number of relevant modules', function () {
    $result = evaluateExample([
        'q1' => 'q1_o1',
        'q2' => 'q2_o3',
        'q3' => 'q3_o3',
        'q4' => ['q4_o2'],
    ]);

    $relevantCount = collect($result->rankedModules)->filter->relevant->count();

    expect($relevantCount)->toBe(2)
        ->and(collect($result->rankedModules)->pluck('rank')->filter()->max())->toBe($relevantCount);
});
