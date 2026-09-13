<?php

namespace App\QuizConfig\Analytics;

use App\Models\QuizEvent;
use App\Models\QuizSession;

class EventRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(QuizSession $quizSession, QuizEventType $type, array $metadata = []): void
    {
        if ($quizSession->is_preview) {
            return;
        }

        QuizEvent::create([
            'quiz_session_id' => $quizSession->id,
            'config_version_id' => $quizSession->config_version_id,
            'type' => $type,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
