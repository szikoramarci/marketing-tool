<?php

namespace App\QuizConfig;

use Illuminate\Validation\ValidationException;

class AnswerSubmissionValidator
{
    /**
     * Checks a raw answers payload against this specific config version's questions
     * (required-ness, and that every submitted option id actually belongs to that
     * question) and returns the cleaned question_id => answer array ready for
     * AnswerVector. The rules are inherently dynamic per config version, so this is a
     * plain method rather than a static FormRequest.
     *
     * @param  array<string, mixed>  $rawAnswers
     * @return array<string, string|array<int, string>>
     *
     * @throws ValidationException
     */
    public function validate(object $config, array $rawAnswers): array
    {
        $errors = [];
        $cleaned = [];

        foreach ($config->questions as $question) {
            $raw = $rawAnswers[$question->id] ?? null;
            $optionIds = array_map(fn (object $option) => $option->id, $question->options);

            if ($question->input_type === 'multi') {
                $selected = is_array($raw)
                    ? array_values(array_filter($raw, fn ($value) => $value !== null && $value !== ''))
                    : [];

                if (array_diff($selected, $optionIds) !== []) {
                    $errors["answers.{$question->id}"] = ['Érvénytelen válasz.'];
                } elseif ($question->required && $selected === []) {
                    $errors["answers.{$question->id}"] = ['Ez a kérdés kötelező.'];
                } else {
                    $cleaned[$question->id] = $selected;
                }

                continue;
            }

            $selected = is_string($raw) && $raw !== '' ? $raw : null;

            if ($selected !== null && ! in_array($selected, $optionIds, true)) {
                $errors["answers.{$question->id}"] = ['Érvénytelen válasz.'];
            } elseif ($question->required && $selected === null) {
                $errors["answers.{$question->id}"] = ['Ez a kérdés kötelező.'];
            } elseif ($selected !== null) {
                $cleaned[$question->id] = $selected;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $cleaned;
    }
}
