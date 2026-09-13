<?php

use App\Models\Campaign;
use App\Models\ConfigVersion;
use App\Models\QuizEvent;
use App\Models\QuizSession;
use App\QuizConfig\Analytics\FunnelQueries;
use App\QuizConfig\Analytics\QuizEventType;

/**
 * @param  array<int, string>  $questionIds
 */
function makeVariant(Campaign $campaign, array $questionIds): ConfigVersion
{
    $configVersion = ConfigVersion::factory()->for($campaign)->create();

    $content = $configVersion->content;
    $content['questions'] = array_map(fn (string $id) => ['id' => $id], $questionIds);
    $configVersion->content = $content;
    $configVersion->save();

    return $configVersion;
}

function makeSession(ConfigVersion $configVersion): QuizSession
{
    return QuizSession::factory()->create([
        'campaign_id' => $configVersion->campaign_id,
        'config_version_id' => $configVersion->id,
    ]);
}

/**
 * @param  array<string, mixed>  $metadata
 */
function logEvent(QuizSession $session, QuizEventType $type, array $metadata = []): void
{
    QuizEvent::create([
        'quiz_session_id' => $session->id,
        'config_version_id' => $session->config_version_id,
        'type' => $type,
        'metadata' => $metadata,
    ]);
}

it('computes the completion funnel per variant', function () {
    $campaign = Campaign::factory()->create();
    $variantA = makeVariant($campaign, ['qa1', 'qa2']);
    $variantB = makeVariant($campaign, ['qb1', 'qb2', 'qb3']);

    $aSessions = collect(range(1, 4))->map(fn () => makeSession($variantA));
    $aSessions->each(fn ($s) => logEvent($s, QuizEventType::SessionStarted));
    $aSessions->take(3)->each(fn ($s) => logEvent($s, QuizEventType::EvaluationCompleted, ['matched_group_ids' => ['g1']]));

    $bSessions = collect(range(1, 2))->map(fn () => makeSession($variantB));
    $bSessions->each(fn ($s) => logEvent($s, QuizEventType::SessionStarted));
    $bSessions->take(1)->each(fn ($s) => logEvent($s, QuizEventType::EvaluationCompleted, ['matched_group_ids' => ['g2']]));

    $funnel = (new FunnelQueries)->completionFunnelByVariant($campaign)->keyBy('config_version_id');

    expect($funnel[$variantA->id]['started'])->toBe(4)
        ->and($funnel[$variantA->id]['completed'])->toBe(3)
        ->and($funnel[$variantA->id]['completion_rate'])->toEqual(0.75)
        ->and($funnel[$variantB->id]['started'])->toBe(2)
        ->and($funnel[$variantB->id]['completed'])->toBe(1)
        ->and($funnel[$variantB->id]['completion_rate'])->toEqual(0.5);
});

it('computes dropout by question with normalized position across differently-sized variants', function () {
    $campaign = Campaign::factory()->create();
    $variantA = makeVariant($campaign, ['qa1', 'qa2']);
    $variantB = makeVariant($campaign, ['qb1', 'qb2', 'qb3']);

    $aSessions = collect(range(1, 4))->map(fn () => makeSession($variantA));
    foreach ($aSessions as $session) {
        logEvent($session, QuizEventType::QuestionShown, ['question_id' => 'qa1']);
    }
    foreach ($aSessions->take(3) as $session) {
        logEvent($session, QuizEventType::QuestionShown, ['question_id' => 'qa2']);
    }

    $bSessions = collect(range(1, 2))->map(fn () => makeSession($variantB));
    logEvent($bSessions[0], QuizEventType::QuestionShown, ['question_id' => 'qb1']);
    logEvent($bSessions[1], QuizEventType::QuestionShown, ['question_id' => 'qb1']);
    logEvent($bSessions[0], QuizEventType::QuestionShown, ['question_id' => 'qb2']);
    logEvent($bSessions[0], QuizEventType::QuestionShown, ['question_id' => 'qb3']);

    $rows = (new FunnelQueries)->dropoutByQuestion($campaign)
        ->keyBy(fn ($row) => $row['config_version_id'].'|'.$row['question_id']);

    expect($rows["{$variantA->id}|qa1"]['shown_count'])->toBe(4)
        ->and($rows["{$variantA->id}|qa1"]['normalized_position'])->toEqual(0.0)
        ->and($rows["{$variantA->id}|qa2"]['shown_count'])->toBe(3)
        ->and($rows["{$variantA->id}|qa2"]['normalized_position'])->toEqual(1.0)
        ->and($rows["{$variantB->id}|qb1"]['shown_count'])->toBe(2)
        ->and($rows["{$variantB->id}|qb1"]['normalized_position'])->toEqual(0.0)
        ->and($rows["{$variantB->id}|qb2"]['shown_count'])->toBe(1)
        ->and($rows["{$variantB->id}|qb2"]['normalized_position'])->toEqual(0.5)
        ->and($rows["{$variantB->id}|qb3"]['shown_count'])->toBe(1)
        ->and($rows["{$variantB->id}|qb3"]['normalized_position'])->toEqual(1.0);
});

it('computes group distribution per variant', function () {
    $campaign = Campaign::factory()->create();
    $variant = makeVariant($campaign, ['q1']);

    $sessions = collect(range(1, 3))->map(fn () => makeSession($variant));
    logEvent($sessions[0], QuizEventType::EvaluationCompleted, ['matched_group_ids' => ['group_a']]);
    logEvent($sessions[1], QuizEventType::EvaluationCompleted, ['matched_group_ids' => ['group_a']]);
    logEvent($sessions[2], QuizEventType::EvaluationCompleted, ['matched_group_ids' => ['group_b']]);

    $distribution = (new FunnelQueries)->groupDistribution($campaign)->keyBy('group_id');

    expect($distribution['group_a']['count'])->toBe(2)
        ->and($distribution['group_b']['count'])->toBe(1);
});
