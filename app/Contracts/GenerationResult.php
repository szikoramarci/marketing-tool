<?php

namespace App\Contracts;

readonly class GenerationResult
{
    /**
     * @param  string  $documentJson  Raw JSON text — not pre-validated. The caller runs
     *                                this through ConfigSchemaValidator (via ConfigVersion::createFromDocument()) the
     *                                same way it would validate a hand-authored document.
     */
    public function __construct(
        public string $documentJson,
        public string $model,
    ) {}
}
