<?php

namespace App\QuizConfig\Validation;

readonly class ValidationReport
{
    /**
     * @param  ValidationIssue[]  $issues
     */
    public function __construct(
        public array $issues,
        public ?ValidationStatistics $statistics = null,
    ) {}

    public function isValid(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === IssueSeverity::Error) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return ValidationIssue[]
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, fn (ValidationIssue $issue) => $issue->severity === IssueSeverity::Error));
    }

    /**
     * @return ValidationIssue[]
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, fn (ValidationIssue $issue) => $issue->severity === IssueSeverity::Warning));
    }
}
