<?php

use App\Models\Campaign;
use App\Models\ConfigVersion;
use App\Models\QuizEvent;
use App\Models\QuizSession;
use App\Models\User;
use App\QuizConfig\Analytics\QuizEventType;
use App\States\ConfigVersionStatus\Active;

it('records events through the full public quiz flow', function () {
    $campaign = Campaign::factory()->create();
    $configVersion = ConfigVersion::factory()->for($campaign)->create();
    $configVersion->status->transitionTo(Active::class);

    $this->get("/campaigns/{$campaign->slug}");
    $quizSession = QuizSession::sole();

    expect(QuizEvent::where('type', QuizEventType::SessionStarted)->count())->toBe(1);

    $this->post("/sessions/{$quizSession->id}/question-shown", ['question_id' => 'q1'])
        ->assertNoContent();

    $shown = QuizEvent::where('type', QuizEventType::QuestionShown)->sole();
    expect($shown->metadata['question_id'])->toBe('q1')
        ->and($shown->metadata['position'])->toBe(0)
        // toEqual, not toBe: JSON storage doesn't preserve int-vs-float (0.0 round-trips as 0).
        ->and($shown->metadata['normalized_position'])->toEqual(0.0);

    $this->post("/sessions/{$quizSession->id}/answers", ['answers' => ['q1' => 'q1_o1']])
        ->assertRedirect("/results/{$quizSession->id}");

    expect(QuizEvent::where('type', QuizEventType::QuestionAnswered)->count())->toBe(1);
    $evaluated = QuizEvent::where('type', QuizEventType::EvaluationCompleted)->sole();
    expect($evaluated->metadata['matched_group_ids'])->toBe(['group_fallback']);

    $this->get("/results/{$quizSession->id}")->assertOk();

    $viewed = QuizEvent::where('type', QuizEventType::ResultPageViewed)->sole();
    expect($viewed->metadata['group_id'])->toBe('group_fallback');

    expect(QuizEvent::where('quiz_session_id', $quizSession->id)->count())->toBe(5);
});

it('never records events for a preview session', function () {
    $user = User::factory()->create();
    $configVersion = ConfigVersion::factory()->create();

    $this->actingAs($user)->get("/preview/{$configVersion->id}");
    $quizSession = QuizSession::sole();

    $this->post("/sessions/{$quizSession->id}/question-shown", ['question_id' => 'q1']);
    $this->post("/sessions/{$quizSession->id}/answers", ['answers' => ['q1' => 'q1_o1']]);
    $this->get("/results/{$quizSession->id}");

    expect(QuizEvent::count())->toBe(0);
});
