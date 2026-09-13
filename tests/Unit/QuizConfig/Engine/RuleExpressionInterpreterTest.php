<?php

use App\QuizConfig\Engine\AnswerVector;
use App\QuizConfig\Engine\EvaluationContext;
use App\QuizConfig\Engine\ModuleRelevanceResult;
use App\QuizConfig\Engine\RuleExpressionInterpreter;

function node(array $data): object
{
    return json_decode(json_encode($data));
}

function evalContext(array $labelSums = [], array $answers = [], array $moduleRelevance = []): EvaluationContext
{
    return new EvaluationContext($labelSums, new AnswerVector($answers), $moduleRelevance);
}

it('evaluates label_sum and constant as numeric leaves', function () {
    $interpreter = new RuleExpressionInterpreter;
    $ctx = evalContext(labelSums: ['content_a' => 4.5]);

    expect($interpreter->evaluateNumeric(node(['type' => 'label_sum', 'label' => 'content_a']), $ctx))->toBe(4.5)
        ->and($interpreter->evaluateNumeric(node(['type' => 'label_sum', 'label' => 'missing']), $ctx))->toBe(0.0)
        ->and($interpreter->evaluateNumeric(node(['type' => 'constant', 'value' => 3]), $ctx))->toBe(3.0);
});

it('evaluates every comparison operator', function (string $operator, float $left, float $right, bool $expected) {
    $interpreter = new RuleExpressionInterpreter;
    $ctx = evalContext();

    $result = $interpreter->evaluateBoolean(node([
        'type' => 'comparison',
        'operator' => $operator,
        'left' => ['type' => 'constant', 'value' => $left],
        'right' => ['type' => 'constant', 'value' => $right],
    ]), $ctx);

    expect($result)->toBe($expected);
})->with([
    ['eq', 3, 3, true], ['eq', 3, 4, false],
    ['ne', 3, 4, true], ['ne', 3, 3, false],
    ['lt', 2, 3, true], ['lt', 3, 3, false],
    ['lte', 3, 3, true], ['lte', 4, 3, false],
    ['gt', 4, 3, true], ['gt', 3, 3, false],
    ['gte', 3, 3, true], ['gte', 2, 3, false],
]);

it('evaluates answer_selected against the answer vector', function () {
    $interpreter = new RuleExpressionInterpreter;
    $ctx = evalContext(answers: ['q1' => 'q1_o1', 'q4' => ['q4_o1', 'q4_o2']]);

    expect($interpreter->evaluateBoolean(node(['type' => 'answer_selected', 'question' => 'q1', 'option' => 'q1_o1']), $ctx))->toBeTrue()
        ->and($interpreter->evaluateBoolean(node(['type' => 'answer_selected', 'question' => 'q1', 'option' => 'q1_o2']), $ctx))->toBeFalse()
        ->and($interpreter->evaluateBoolean(node(['type' => 'answer_selected', 'question' => 'q4', 'option' => 'q4_o2']), $ctx))->toBeTrue();
});

it('evaluates and/or/not combinations', function () {
    $interpreter = new RuleExpressionInterpreter;
    $ctx = evalContext();

    $true = ['type' => 'comparison', 'operator' => 'eq', 'left' => ['type' => 'constant', 'value' => 1], 'right' => ['type' => 'constant', 'value' => 1]];
    $false = ['type' => 'comparison', 'operator' => 'eq', 'left' => ['type' => 'constant', 'value' => 1], 'right' => ['type' => 'constant', 'value' => 2]];

    expect($interpreter->evaluateBoolean(node(['type' => 'and', 'operands' => [$true, $true]]), $ctx))->toBeTrue()
        ->and($interpreter->evaluateBoolean(node(['type' => 'and', 'operands' => [$true, $false]]), $ctx))->toBeFalse()
        ->and($interpreter->evaluateBoolean(node(['type' => 'or', 'operands' => [$false, $true]]), $ctx))->toBeTrue()
        ->and($interpreter->evaluateBoolean(node(['type' => 'or', 'operands' => [$false, $false]]), $ctx))->toBeFalse()
        ->and($interpreter->evaluateBoolean(node(['type' => 'not', 'operand' => $false]), $ctx))->toBeTrue()
        ->and($interpreter->evaluateBoolean(node(['type' => 'not', 'operand' => $true]), $ctx))->toBeFalse();
});

it('evaluates module_relevant and module_top_rank against precomputed module results', function () {
    $interpreter = new RuleExpressionInterpreter;
    $ctx = evalContext(moduleRelevance: [
        'module_a' => new ModuleRelevanceResult('module_a', matched: true, score: 8, relevant: true, rank: 1),
        'module_b' => new ModuleRelevanceResult('module_b', matched: true, score: 3, relevant: false, rank: null),
    ]);

    expect($interpreter->evaluateBoolean(node(['type' => 'module_relevant', 'module' => 'module_a']), $ctx))->toBeTrue()
        ->and($interpreter->evaluateBoolean(node(['type' => 'module_relevant', 'module' => 'module_b']), $ctx))->toBeFalse()
        ->and($interpreter->evaluateBoolean(node(['type' => 'module_top_rank', 'module' => 'module_a', 'within' => 1]), $ctx))->toBeTrue()
        ->and($interpreter->evaluateBoolean(node(['type' => 'module_top_rank', 'module' => 'module_a', 'within' => 0]), $ctx))->toBeFalse()
        ->and($interpreter->evaluateBoolean(node(['type' => 'module_top_rank', 'module' => 'module_b', 'within' => 5]), $ctx))->toBeFalse();
});

it('throws for an unknown module reference', function () {
    $interpreter = new RuleExpressionInterpreter;
    $ctx = evalContext();

    $interpreter->evaluateBoolean(node(['type' => 'module_relevant', 'module' => 'does_not_exist']), $ctx);
})->throws(InvalidArgumentException::class);
