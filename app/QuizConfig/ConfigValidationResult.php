<?php

namespace App\QuizConfig;

readonly class ConfigValidationResult
{
    /**
     * @param  ConfigValidationError[]  $errors
     */
    private function __construct(
        public bool $valid,
        public array $errors,
    ) {}

    public static function valid(): self
    {
        return new self(valid: true, errors: []);
    }

    /**
     * @param  ConfigValidationError[]  $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(valid: false, errors: $errors);
    }
}
