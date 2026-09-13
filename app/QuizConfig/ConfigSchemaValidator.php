<?php

namespace App\QuizConfig;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Validator;

class ConfigSchemaValidator
{
    private readonly Validator $validator;

    private readonly ErrorFormatter $formatter;

    public function __construct()
    {
        $this->validator = new Validator(max_errors: 100, stop_at_first_error: false);
        $this->formatter = new ErrorFormatter;
    }

    public function validate(object $config): ConfigValidationResult
    {
        $schema = json_decode(file_get_contents(__DIR__.'/Schema/quiz-config.schema.json'));

        $result = $this->validator->validate($config, $schema);

        if ($result->isValid()) {
            return ConfigValidationResult::valid();
        }

        return ConfigValidationResult::invalid($this->collectErrors($result->error()));
    }

    /**
     * @return ConfigValidationError[]
     */
    private function collectErrors(ValidationError $error): array
    {
        $subErrors = $error->subErrors();

        if ($subErrors === []) {
            return [$this->toConfigValidationError($error)];
        }

        // opis/json-schema's PropertiesKeyword only registers sibling properties as
        // "checked" when none of them fail; when one does, the sibling
        // AdditionalPropertiesKeyword then spuriously reports every other, perfectly
        // valid property at this same location as "additional". That sibling error
        // carries no real information here, so skip it.
        $hasSiblingPropertiesFailure = collect($subErrors)->contains(
            fn (ValidationError $subError) => $subError->keyword() === 'properties',
        );

        $errors = [];

        foreach ($subErrors as $subError) {
            if ($hasSiblingPropertiesFailure && $subError->keyword() === 'additionalProperties') {
                continue;
            }

            array_push($errors, ...$this->collectErrors($subError));
        }

        return $errors;
    }

    private function toConfigValidationError(ValidationError $error): ConfigValidationError
    {
        $pointer = JsonPointer::pathToString($error->data()->fullPath());

        return new ConfigValidationError(
            pointer: $pointer === '' ? '/' : $pointer,
            message: $this->formatter->formatErrorMessage($error),
        );
    }
}
