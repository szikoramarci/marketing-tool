<?php

namespace App\QuizConfig\Engine;

readonly class ModuleRelevanceResult
{
    public function __construct(
        public string $moduleId,
        public bool $matched,
        public float $score,
        public bool $relevant,
        public ?int $rank = null,
    ) {}

    public function withRank(int $rank): self
    {
        return new self($this->moduleId, $this->matched, $this->score, $this->relevant, $rank);
    }
}
