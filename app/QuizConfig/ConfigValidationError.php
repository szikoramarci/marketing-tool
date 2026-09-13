<?php

namespace App\QuizConfig;

readonly class ConfigValidationError
{
    public function __construct(
        public string $pointer,
        public string $message,
    ) {}

    public function __toString(): string
    {
        return "{$this->pointer}: {$this->message}";
    }
}
