<?php

namespace App\QuizConfig\Analytics;

use App\Models\Campaign;
use App\Models\QuizEvent;
use Illuminate\Support\Collection;

/**
 * Ready-made queries per spec section 7. Aggregates in PHP rather than DB-specific JSON
 * query syntax — simple and portable at this stage/volume. Preview sessions never produce
 * QuizEvent rows (see EventRecorder), so none of these need to filter them out.
 */
class FunnelQueries
{
    /**
     * @return Collection<int, array{config_version_id: string, started: int, completed: int, completion_rate: float}>
     */
    public function completionFunnelByVariant(Campaign $campaign): Collection
    {
        $configVersionIds = $campaign->configVersions()->pluck('id');

        $started = $this->countByConfigVersion($configVersionIds, QuizEventType::SessionStarted);
        $completed = $this->countByConfigVersion($configVersionIds, QuizEventType::EvaluationCompleted);

        return $configVersionIds->map(function (string $configVersionId) use ($started, $completed) {
            $startedCount = $started->get($configVersionId, 0);
            $completedCount = $completed->get($configVersionId, 0);

            return [
                'config_version_id' => $configVersionId,
                'started' => $startedCount,
                'completed' => $completedCount,
                'completion_rate' => $startedCount > 0 ? $completedCount / $startedCount : 0.0,
            ];
        })->values();
    }

    /**
     * @return Collection<int, array{config_version_id: string, question_id: string, position: int, normalized_position: float, shown_count: int}>
     */
    public function dropoutByQuestion(Campaign $campaign): Collection
    {
        $configVersions = $campaign->configVersions()->get();

        $shownEvents = QuizEvent::whereIn('config_version_id', $configVersions->pluck('id'))
            ->where('type', QuizEventType::QuestionShown)
            ->get();

        $rows = collect();

        foreach ($configVersions as $configVersion) {
            $questionIds = array_column($configVersion->content['questions'], 'id');
            $eventsForVersion = $shownEvents->where('config_version_id', $configVersion->id);

            foreach ($questionIds as $position => $questionId) {
                $shownCount = $eventsForVersion
                    ->filter(fn (QuizEvent $event) => ($event->metadata['question_id'] ?? null) === $questionId)
                    ->count();

                $rows->push([
                    'config_version_id' => $configVersion->id,
                    'question_id' => $questionId,
                    'position' => $position,
                    'normalized_position' => self::normalizedPosition($position, count($questionIds)),
                    'shown_count' => $shownCount,
                ]);
            }
        }

        return $rows;
    }

    /**
     * @return Collection<int, array{config_version_id: string, group_id: ?string, count: int}>
     */
    public function groupDistribution(Campaign $campaign): Collection
    {
        $configVersionIds = $campaign->configVersions()->pluck('id');

        $events = QuizEvent::whereIn('config_version_id', $configVersionIds)
            ->where('type', QuizEventType::EvaluationCompleted)
            ->get()
            ->map(fn (QuizEvent $event) => [
                'config_version_id' => $event->config_version_id,
                'group_id' => $event->metadata['matched_group_ids'][0] ?? null,
            ]);

        return $events
            ->groupBy(fn (array $row) => $row['config_version_id'].'|'.$row['group_id'])
            ->map(fn (Collection $rows) => [
                'config_version_id' => $rows->first()['config_version_id'],
                'group_id' => $rows->first()['group_id'],
                'count' => $rows->count(),
            ])
            ->values();
    }

    /**
     * Raw position alone is misleading once variants have different question counts —
     * this is what makes cross-variant dropout comparison meaningful.
     */
    public static function normalizedPosition(int $position, int $totalQuestions): float
    {
        return $totalQuestions > 1 ? $position / ($totalQuestions - 1) : 0.0;
    }

    /**
     * @param  Collection<int, string>  $configVersionIds
     * @return Collection<string, int>
     */
    private function countByConfigVersion(Collection $configVersionIds, QuizEventType $type): Collection
    {
        return QuizEvent::whereIn('config_version_id', $configVersionIds)
            ->where('type', $type)
            ->get()
            ->countBy('config_version_id');
    }
}
