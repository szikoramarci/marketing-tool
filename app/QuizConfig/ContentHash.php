<?php

namespace App\QuizConfig;

class ContentHash
{
    /**
     * @param  array<string, mixed>  $content
     */
    public static function compute(array $content): string
    {
        return hash('sha256', json_encode(self::canonicalize($content), JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        $canonicalized = array_map(self::canonicalize(...), $value);

        if (! $isList) {
            ksort($canonicalized);
        }

        return $canonicalized;
    }
}
