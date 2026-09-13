<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\ConfigVersion;
use App\Models\QuizSession;
use App\QuizConfig\Analytics\EventRecorder;
use App\QuizConfig\Analytics\FunnelQueries;
use App\QuizConfig\Analytics\QuizEventType;
use App\QuizConfig\AnswerSubmissionValidator;
use App\QuizConfig\Engine\AnswerVector;
use App\QuizConfig\Engine\EvaluationEngine;
use App\QuizConfig\VariantSelector;
use App\QuizConfig\VisitorToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class QuizController extends Controller
{
    public function start(Request $request, Campaign $campaign, VariantSelector $selector, EventRecorder $recorder): RedirectResponse
    {
        $visitorToken = VisitorToken::resolve($request);
        $configVersion = $selector->select($campaign, $visitorToken);

        abort_if($configVersion === null, 404);

        $quizSession = QuizSession::create([
            'campaign_id' => $campaign->id,
            'config_version_id' => $configVersion->id,
            'is_preview' => false,
            'started_at' => now(),
        ]);

        $recorder->record($quizSession, QuizEventType::SessionStarted);

        return redirect()->route('quiz.show', $quizSession);
    }

    public function preview(ConfigVersion $configVersion): RedirectResponse
    {
        $quizSession = QuizSession::create([
            'campaign_id' => $configVersion->campaign_id,
            'config_version_id' => $configVersion->id,
            'is_preview' => true,
            'started_at' => now(),
        ]);

        return redirect()->route('quiz.show', $quizSession);
    }

    public function show(QuizSession $quizSession): View|RedirectResponse
    {
        if ($quizSession->completed_at !== null) {
            return redirect()->route('results.show', $quizSession);
        }

        return view('quiz.show', [
            'quizSession' => $quizSession,
            'config' => $quizSession->configVersion->toConfigObject(),
        ]);
    }

    public function questionShown(Request $request, QuizSession $quizSession, EventRecorder $recorder): Response
    {
        $questionId = (string) $request->input('question_id');
        $questionIds = array_column($quizSession->configVersion->toConfigObject()->questions, 'id');
        $position = array_search($questionId, $questionIds, true);

        if ($position !== false) {
            $recorder->record($quizSession, QuizEventType::QuestionShown, [
                'question_id' => $questionId,
                'position' => $position,
                'normalized_position' => FunnelQueries::normalizedPosition($position, count($questionIds)),
            ]);
        }

        return response()->noContent();
    }

    public function submit(
        Request $request,
        QuizSession $quizSession,
        AnswerSubmissionValidator $validator,
        EvaluationEngine $engine,
        EventRecorder $recorder,
    ): RedirectResponse {
        if ($quizSession->completed_at !== null) {
            return redirect()->route('results.show', $quizSession);
        }

        $config = $quizSession->configVersion->toConfigObject();
        $cleanedAnswers = $validator->validate($config, (array) $request->input('answers', []));

        $result = $engine->evaluate($config, new AnswerVector($cleanedAnswers));

        foreach ($cleanedAnswers as $questionId => $answer) {
            $recorder->record($quizSession, QuizEventType::QuestionAnswered, [
                'question_id' => $questionId,
                'answer' => $answer,
            ]);
        }

        $recorder->record($quizSession, QuizEventType::EvaluationCompleted, [
            'matched_group_ids' => $result->matchedGroupIds,
        ]);

        $quizSession->answers = $cleanedAnswers;
        $quizSession->markCompleted($result);

        return redirect()->route('results.show', $quizSession);
    }

    public function result(QuizSession $quizSession, EventRecorder $recorder): View
    {
        abort_if($quizSession->completed_at === null, 404);

        $groupId = $quizSession->result['matched_group_ids'][0] ?? null;
        $group = collect($quizSession->configVersion->content['evaluation_groups'])
            ->firstWhere('id', $groupId);

        abort_if($group === null, 404);

        $recorder->record($quizSession, QuizEventType::ResultPageViewed, [
            'group_id' => $group['id'],
        ]);

        return view('quiz.result', [
            'quizSession' => $quizSession,
            'group' => $group,
        ]);
    }
}
