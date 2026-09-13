<?php

namespace App\QuizConfig\Engine;

use InvalidArgumentException;

readonly class EvaluationContext
{
    /**
     * @param  array<string, float>  $labelSums
     * @param  array<string, ModuleRelevanceResult>  $moduleRelevance  Empty while evaluating module rules themselves — moduleRuleExpression cannot reference other modules, so this is never read at that point.
     */
    public function __construct(
        private array $labelSums,
        public AnswerVector $answers,
        private array $moduleRelevance = [],
    ) {}

    public function labelSum(string $label): float
    {
        return $this->labelSums[$label] ?? 0.0;
    }

    public function moduleResult(string $moduleId): ModuleRelevanceResult
    {
        if (! isset($this->moduleRelevance[$moduleId])) {
            throw new InvalidArgumentException("Unknown module reference: '{$moduleId}'.");
        }

        return $this->moduleRelevance[$moduleId];
    }
}
