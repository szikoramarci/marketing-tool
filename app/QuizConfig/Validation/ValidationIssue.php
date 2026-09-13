<?php

namespace App\QuizConfig\Validation;

readonly class ValidationIssue
{
    public function __construct(
        public IssueSeverity $severity,
        public string $code,
        public string $message,
    ) {}
}
