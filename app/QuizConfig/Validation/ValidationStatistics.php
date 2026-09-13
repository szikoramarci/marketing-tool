<?php

namespace App\QuizConfig\Validation;

readonly class ValidationStatistics
{
    /**
     * @param  array<string, float>  $groupHitRates  Share of the domain where each group is primary.
     * @param  array<string, float>  $moduleReachRates  Share of the domain where each module is relevant.
     */
    public function __construct(
        public int $domainSize,
        public bool $sampled,
        public array $groupHitRates,
        public array $moduleReachRates,
        public float $fallbackRate,
    ) {}
}
