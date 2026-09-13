<?php

use App\Models\Campaign;
use App\Models\ConfigVersion;
use App\Models\QuizSession;
use App\Models\User;
use App\States\ConfigVersionStatus\Active;

it('completes the public quiz flow end to end and shows the matched result', function () {
    $campaign = Campaign::factory()->create();
    $configVersion = ConfigVersion::factory()->for($campaign)->create();
    $configVersion->status->transitionTo(Active::class);

    $startResponse = $this->get("/campaigns/{$campaign->slug}");
    $startResponse->assertRedirect();

    $quizSession = QuizSession::sole();
    expect($quizSession->config_version_id)->toBe($configVersion->id)
        ->and($quizSession->is_preview)->toBeFalse()
        ->and($quizSession->completed_at)->toBeNull();

    $this->get("/sessions/{$quizSession->id}")
        ->assertOk()
        ->assertSee('Melyik igaz rád?');

    $submitResponse = $this->post("/sessions/{$quizSession->id}/answers", [
        'answers' => ['q1' => 'q1_o1'],
    ]);
    $submitResponse->assertRedirect("/results/{$quizSession->id}");

    $quizSession->refresh();
    expect($quizSession->completed_at)->not->toBeNull()
        ->and($quizSession->answers)->toBe(['q1' => 'q1_o1']);

    $this->get("/results/{$quizSession->id}")
        ->assertOk()
        ->assertSee('Eredmény');
});

it('redirects a completed session straight to its results', function () {
    $quizSession = QuizSession::factory()->create(['completed_at' => now()]);

    $this->get("/sessions/{$quizSession->id}")
        ->assertRedirect("/results/{$quizSession->id}");
});

it('rejects a submission missing a required answer', function () {
    $quizSession = QuizSession::factory()->create();

    $this->post("/sessions/{$quizSession->id}/answers", ['answers' => []])
        ->assertSessionHasErrors();

    expect($quizSession->fresh()->completed_at)->toBeNull();
});

it('requires authentication to preview a config version', function () {
    $configVersion = ConfigVersion::factory()->create();

    $this->get("/preview/{$configVersion->id}")
        ->assertRedirect('/admin/login');
});

it('lets an authenticated editor preview any config version, including drafts', function () {
    $user = User::factory()->create();
    $configVersion = ConfigVersion::factory()->create(); // stays draft

    $this->actingAs($user)
        ->get("/preview/{$configVersion->id}")
        ->assertRedirect();

    $quizSession = QuizSession::sole();
    expect($quizSession->is_preview)->toBeTrue()
        ->and($quizSession->config_version_id)->toBe($configVersion->id);
});
