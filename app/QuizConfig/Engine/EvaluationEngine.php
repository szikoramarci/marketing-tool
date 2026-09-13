<?php

namespace App\QuizConfig\Engine;

use InvalidArgumentException;

class EvaluationEngine
{
    public function __construct(
        private readonly RuleExpressionInterpreter $interpreter = new RuleExpressionInterpreter,
    ) {}

    public function evaluate(object $config, AnswerVector $answers): EvaluationResult
    {
        $labelSums = $this->computeLabelSums($config, $answers);

        $moduleRelevance = $this->computeModuleRelevance(
            $config,
            new EvaluationContext($labelSums, $answers),
        );

        $matchedGroupIds = $this->matchGroups(
            $config,
            new EvaluationContext($labelSums, $answers, $moduleRelevance),
        );

        return new EvaluationResult($labelSums, $matchedGroupIds, array_values($moduleRelevance));
    }

    /**
     * @return array<string, float>
     */
    private function computeLabelSums(object $config, AnswerVector $answers): array
    {
        $sums = [];

        foreach ($config->questions as $question) {
            foreach ($answers->selectedOptionIds($question->id) as $optionId) {
                $option = $this->findOption($question, $optionId);

                foreach ((array) $option->label_weights as $label => $weight) {
                    $sums[$label] = ($sums[$label] ?? 0.0) + (float) $weight;
                }
            }
        }

        return $sums;
    }

    private function findOption(object $question, string $optionId): object
    {
        foreach ($question->options as $option) {
            if ($option->id === $optionId) {
                return $option;
            }
        }

        throw new InvalidArgumentException("Unknown option '{$optionId}' for question '{$question->id}'.");
    }

    /**
     * @return array<string, ModuleRelevanceResult> Keyed by module id, in declared order.
     */
    private function computeModuleRelevance(object $config, EvaluationContext $context): array
    {
        $results = [];

        foreach ($config->modules as $module) {
            $matched = $this->interpreter->evaluateBoolean($module->relevance_rule, $context);
            $score = $matched ? (float) $module->relevance_score : 0.0;
            $relevant = $matched && $score > (float) $module->threshold;

            $results[$module->id] = new ModuleRelevanceResult(
                moduleId: $module->id,
                matched: $matched,
                score: $score,
                relevant: $relevant,
            );
        }

        $ranked = array_values(array_filter($results, fn (ModuleRelevanceResult $result) => $result->relevant));

        // usort is stable since PHP 8.0: equal scores keep the order above, i.e. modules[] declaration order.
        usort($ranked, fn (ModuleRelevanceResult $a, ModuleRelevanceResult $b) => $b->score <=> $a->score);

        foreach ($ranked as $index => $result) {
            $results[$result->moduleId] = $result->withRank($index + 1);
        }

        return $results;
    }

    /**
     * @return array<int, string>
     */
    private function matchGroups(object $config, EvaluationContext $context): array
    {
        $groups = $config->evaluation_groups;

        $nonFallback = array_values(array_filter($groups, fn (object $group) => ! $group->is_fallback));
        usort($nonFallback, fn (object $a, object $b) => $a->priority <=> $b->priority);

        $matched = array_values(array_filter(
            $nonFallback,
            fn (object $group) => $this->interpreter->evaluateBoolean($group->rule, $context),
        ));

        if ($matched === []) {
            $fallback = current(array_filter($groups, fn (object $group) => $group->is_fallback));

            return [$fallback->id];
        }

        $primary = $matched[0];

        if (! $primary->allow_multiple) {
            return [$primary->id];
        }

        if (isset($primary->max_classifications)) {
            $matched = array_slice($matched, 0, $primary->max_classifications);
        }

        return array_map(fn (object $group) => $group->id, $matched);
    }
}
