<?php

namespace Database\Factories;

use App\Models\QuizEvent;
use App\Models\QuizSession;
use App\QuizConfig\Analytics\QuizEventType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizEvent>
 */
class QuizEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quizSession = QuizSession::factory()->create();

        return [
            'quiz_session_id' => $quizSession->id,
            'config_version_id' => $quizSession->config_version_id,
            'type' => QuizEventType::SessionStarted,
            'metadata' => null,
        ];
    }
}
