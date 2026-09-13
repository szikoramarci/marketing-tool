<?php

namespace App\Console\Commands;

use App\QuizConfig\ConfigSchemaValidator;
use App\QuizConfig\Engine\AnswerVector;
use App\QuizConfig\Engine\EvaluationEngine;
use Illuminate\Console\Command;

class EvaluateQuizConfig extends Command
{
    protected $signature = 'quiz-config:evaluate {config : Path to a quiz config JSON file} {answers : Path to an answers JSON file (question id => option id or ids)}';

    protected $description = 'Evaluate an answer vector against a quiz config and print the result';

    public function handle(ConfigSchemaValidator $validator, EvaluationEngine $engine): int
    {
        $configPath = $this->argument('config');
        $answersPath = $this->argument('answers');

        foreach ([$configPath, $answersPath] as $path) {
            if (! is_file($path)) {
                $this->error("File not found: {$path}");

                return self::FAILURE;
            }
        }

        $config = json_decode(file_get_contents($configPath));

        $validation = $validator->validate($config);

        if (! $validation->valid) {
            $this->error(sprintf('Config is invalid (%d error(s)):', count($validation->errors)));

            foreach ($validation->errors as $error) {
                $this->line("  {$error}");
            }

            return self::FAILURE;
        }

        $answers = new AnswerVector(json_decode(file_get_contents($answersPath), associative: true));

        $result = $engine->evaluate($config, $answers);

        $this->info('Label sums:');
        foreach ($result->labelSums as $label => $sum) {
            $this->line("  {$label}: {$sum}");
        }

        $this->info('Matched groups (priority order):');
        foreach ($result->matchedGroupIds as $groupId) {
            $this->line("  {$groupId}");
        }

        $this->info('Ranked modules:');
        $ranked = collect($result->rankedModules)
            ->filter(fn ($module) => $module->relevant)
            ->sortBy('rank');

        foreach ($ranked as $module) {
            $this->line("  #{$module->rank} {$module->moduleId} (score {$module->score})");
        }

        return self::SUCCESS;
    }
}
