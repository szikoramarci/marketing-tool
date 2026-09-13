<?php

namespace App\Contracts;

readonly class GenerationRequest
{
    public function __construct(
        public string $sourceMaterial,
        public string $instructions,
        public string $sourceReference,
        public string $locale = 'hu',
    ) {}
}
