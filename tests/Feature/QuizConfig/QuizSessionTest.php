<?php

use App\Models\QuizSession;
use App\QuizConfig\Engine\AnswerVector;
use App\QuizConfig\Engine\EvaluationEngine;

it('computes the answers hash automatically when answers are set', function () {
    $session = QuizSession::factory()->create(['answers' => ['q1' => 'q1_o1']]);
    $originalHash = $session->answers_hash;

    expect($originalHash)->not->toBeEmpty();

    $session->answers = ['q1' => 'q1_o2'];
    $session->save();

    expect($session->fresh()->answers_hash)->not->toBe($originalHash);
});

it('stores the evaluation result and completion time', function () {
    $session = QuizSession::factory()->create();
    expect($session->completed_at)->toBeNull();

    $engine = new EvaluationEngine;
    $result = $engine->evaluate($session->configVersion->toConfigObject(), new AnswerVector($session->answers));

    $session->markCompleted($result);
    $fresh = $session->fresh();

    // toEqual, not toBe: JSON storage doesn't preserve int-vs-float (e.g. 1.0 round-trips as 1).
    expect($fresh->completed_at)->not->toBeNull()
        ->and($fresh->result['label_sums'])->toEqual($result->labelSums)
        ->and($fresh->result['matched_group_ids'])->toBe($result->matchedGroupIds)
        ->and($fresh->result['ranked_modules'])->toHaveCount(count($result->rankedModules));
});
