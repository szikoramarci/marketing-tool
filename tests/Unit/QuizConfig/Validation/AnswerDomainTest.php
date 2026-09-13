<?php

use App\QuizConfig\Validation\AnswerDomain;

function questionsConfig(array $questions): object
{
    return json_decode(json_encode(['questions' => $questions]));
}

function singleQuestion(string $id, int $optionCount, bool $required = true): array
{
    return [
        'id' => $id,
        'input_type' => 'single',
        'required' => $required,
        'options' => array_map(fn ($i) => ['id' => "{$id}_o{$i}"], range(1, $optionCount)),
    ];
}

function multiQuestion(string $id, int $optionCount, bool $required = true): array
{
    return [
        'id' => $id,
        'input_type' => 'multi',
        'required' => $required,
        'options' => array_map(fn ($i) => ['id' => "{$id}_o{$i}"], range(1, $optionCount)),
    ];
}

it('computes domain size for a required single-choice question as the option count', function () {
    $config = questionsConfig([singleQuestion('q1', 3)]);

    expect((new AnswerDomain)->size($config))->toBe(3);
});

it('computes domain size for an optional single-choice question as option count plus one', function () {
    $config = questionsConfig([singleQuestion('q1', 3, required: false)]);

    expect((new AnswerDomain)->size($config))->toBe(4);
});

it('computes domain size for a required multi-choice question as all non-empty subsets', function () {
    $config = questionsConfig([multiQuestion('q1', 3)]);

    expect((new AnswerDomain)->size($config))->toBe(2 ** 3 - 1);
});

it('computes domain size for an optional multi-choice question as all subsets', function () {
    $config = questionsConfig([multiQuestion('q1', 3, required: false)]);

    expect((new AnswerDomain)->size($config))->toBe(2 ** 3);
});

it('multiplies domain size across questions', function () {
    $config = questionsConfig([
        singleQuestion('q1', 3),
        singleQuestion('q2', 2, required: false),
        multiQuestion('q3', 2),
    ]);

    expect((new AnswerDomain)->size($config))->toBe(3 * 3 * 3);
});

it('exhaustively enumerates exactly as many distinct answer vectors as the domain size', function () {
    $config = questionsConfig([
        singleQuestion('q1', 3),
        multiQuestion('q2', 2, required: false),
    ]);

    $domain = new AnswerDomain;
    $seen = [];

    foreach ($domain->enumerate($config) as $vector) {
        $key = json_encode([
            $vector->selectedOptionIds('q1'),
            $vector->selectedOptionIds('q2'),
        ]);
        $seen[$key] = true;
    }

    expect($seen)->toHaveCount($domain->size($config));
});

it('samples deterministically for a given seed', function () {
    $config = questionsConfig([
        singleQuestion('q1', 5),
        multiQuestion('q2', 4, required: false),
    ]);

    $domain = new AnswerDomain;

    $collect = fn () => collect($domain->sample($config, 20, seed: 7))
        ->map(fn ($vector) => [$vector->selectedOptionIds('q1'), $vector->selectedOptionIds('q2')])
        ->all();

    expect($collect())->toBe($collect());
});
