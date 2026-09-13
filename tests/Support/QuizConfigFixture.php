<?php

namespace Tests\Support;

class QuizConfigFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function example(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/quiz-config/example-config.json')),
            associative: true,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toObject(array $config): object
    {
        return json_decode(json_encode($config));
    }
}
