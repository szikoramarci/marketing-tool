<?php

namespace App\QuizConfig\Engine;

readonly class EvaluationResult
{
    /**
     * @param  array<string, float>  $labelSums
     * @param  array<int, string>  $matchedGroupIds  Priority order.
     * @param  array<int, ModuleRelevanceResult>  $rankedModules  Declared module order; filter/sort by ->rank for the ranking itself.
     */
    public function __construct(
        public array $labelSums,
        public array $matchedGroupIds,
        public array $rankedModules,
    ) {}
}
