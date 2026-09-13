<?php

namespace App\QuizConfig\Engine;

readonly class AnswerVector
{
    /**
     * @param  array<string, string|array<int, string>>  $answers  Question id => selected option id (single questions) or option ids (multi questions).
     */
    public function __construct(
        private array $answers,
    ) {}

    /**
     * @return array<int, string>
     */
    public function selectedOptionIds(string $questionId): array
    {
        if (! array_key_exists($questionId, $this->answers)) {
            return [];
        }

        $value = $this->answers[$questionId];

        return is_array($value) ? $value : [$value];
    }

    public function hasSelected(string $questionId, string $optionId): bool
    {
        return in_array($optionId, $this->selectedOptionIds($questionId), strict: true);
    }
}
