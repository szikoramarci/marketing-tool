<?php

namespace App\Contracts;

use RuntimeException;

interface FeedbackGenerator
{
    /**
     * @throws RuntimeException if generation fails (refused, network/API error, etc.)
     */
    public function generate(GenerationRequest $request): GenerationResult;
}
