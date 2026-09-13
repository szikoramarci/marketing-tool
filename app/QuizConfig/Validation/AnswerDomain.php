<?php

namespace App\QuizConfig\Validation;

use App\QuizConfig\Engine\AnswerVector;
use Generator;

/**
 * Enumerates (or deterministically samples) every possible answer vector for a config,
 * used by ConfigValidator's combinatorial reachability/balance checks. Never used at
 * quiz-taking time — only for validating a config against itself.
 */
class AnswerDomain
{
    private const int EXHAUSTIVE_LIMIT = 5000;

    public function size(object $config): int
    {
        $size = 1;

        foreach ($this->questionStateCounts($config) as $entry) {
            $size *= $entry['stateCount'];
        }

        return $size;
    }

    public function shouldSample(object $config): bool
    {
        return $this->size($config) > self::EXHAUSTIVE_LIMIT;
    }

    /**
     * @return Generator<AnswerVector>
     */
    public function enumerate(object $config): Generator
    {
        $entries = $this->questionStateCounts($config);
        $total = $this->size($config);

        for ($index = 0; $index < $total; $index++) {
            yield $this->decode($entries, $index);
        }
    }

    /**
     * Deterministic: the same config and seed always produce the same sample, so a
     * validation report never changes between runs unless the config does.
     *
     * @return Generator<AnswerVector>
     */
    public function sample(object $config, int $count, int $seed = 42): Generator
    {
        $entries = $this->questionStateCounts($config);

        mt_srand($seed);

        for ($i = 0; $i < $count; $i++) {
            $localIndices = array_map(
                fn (array $entry) => mt_rand(0, $entry['stateCount'] - 1),
                $entries,
            );

            yield $this->decodeFromLocalIndices($entries, $localIndices);
        }
    }

    /**
     * @return array<int, array{question: object, stateCount: int}>
     */
    private function questionStateCounts(object $config): array
    {
        return array_map(function (object $question) {
            $optionCount = count($question->options);

            $stateCount = $question->input_type === 'single'
                ? $optionCount + ($question->required ? 0 : 1)
                : (1 << $optionCount) - ($question->required ? 1 : 0);

            return ['question' => $question, 'stateCount' => $stateCount];
        }, $config->questions);
    }

    /**
     * @param  array<int, array{question: object, stateCount: int}>  $entries
     */
    private function decode(array $entries, int $index): AnswerVector
    {
        $localIndices = [];

        foreach ($entries as $entry) {
            $localIndices[] = $index % $entry['stateCount'];
            $index = intdiv($index, $entry['stateCount']);
        }

        return $this->decodeFromLocalIndices($entries, $localIndices);
    }

    /**
     * @param  array<int, array{question: object, stateCount: int}>  $entries
     * @param  array<int, int>  $localIndices
     */
    private function decodeFromLocalIndices(array $entries, array $localIndices): AnswerVector
    {
        $answers = [];

        foreach ($entries as $i => $entry) {
            $question = $entry['question'];
            $localIndex = $localIndices[$i];
            $optionIds = array_map(fn (object $option) => $option->id, $question->options);
            $optionCount = count($optionIds);

            if ($question->input_type === 'single') {
                if ($localIndex < $optionCount) {
                    $answers[$question->id] = $optionIds[$localIndex];
                }

                // else: the "no answer" state for an optional question — omitted.
                continue;
            }

            $bitmask = $localIndex + ($question->required ? 1 : 0);
            $selected = [];

            for ($bit = 0; $bit < $optionCount; $bit++) {
                if (($bitmask >> $bit) & 1) {
                    $selected[] = $optionIds[$bit];
                }
            }

            $answers[$question->id] = $selected;
        }

        return new AnswerVector($answers);
    }
}
