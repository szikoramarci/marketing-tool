<?php

namespace App\QuizConfig\Validation;

use RuntimeException;

class ConfigVersionNotValidException extends RuntimeException
{
    public function __construct(
        public readonly ValidationReport $report,
    ) {
        $messages = implode('; ', array_map(
            fn (ValidationIssue $issue) => $issue->message,
            $this->report->errors(),
        ));

        parent::__construct("A konfigverzió nem aktiválható, mert nem érvényes: {$messages}");
    }
}
