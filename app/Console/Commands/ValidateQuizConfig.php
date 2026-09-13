<?php

namespace App\Console\Commands;

use App\QuizConfig\ConfigSchemaValidator;
use Illuminate\Console\Command;

class ValidateQuizConfig extends Command
{
    protected $signature = 'quiz-config:validate {path : Path to a quiz config JSON file}';

    protected $description = 'Validate a quiz config JSON file against the quiz config schema';

    public function handle(ConfigSchemaValidator $validator): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $config = json_decode(file_get_contents($path));

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON: '.json_last_error_msg());

            return self::FAILURE;
        }

        $result = $validator->validate($config);

        if ($result->valid) {
            $this->info('valid');

            return self::SUCCESS;
        }

        $this->error(sprintf('invalid (%d error(s)):', count($result->errors)));

        foreach ($result->errors as $error) {
            $this->line("  {$error}");
        }

        return self::FAILURE;
    }
}
