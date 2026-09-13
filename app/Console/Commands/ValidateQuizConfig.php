<?php

namespace App\Console\Commands;

use App\QuizConfig\Validation\ConfigValidator;
use App\QuizConfig\Validation\ValidationReport;
use Illuminate\Console\Command;

class ValidateQuizConfig extends Command
{
    protected $signature = 'quiz-config:validate {path : Path to a quiz config JSON file} {--sample= : Force sampling with this many answer combinations instead of exhaustive enumeration}';

    protected $description = 'Validate a quiz config JSON file: schema shape, referential integrity, reachability and balance';

    public function handle(ConfigValidator $validator): int
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

        $sampleSize = $this->option('sample') !== null ? (int) $this->option('sample') : null;

        $report = $validator->validate($config, $sampleSize);

        $this->renderIssues($report);
        $this->renderStatistics($report);

        return $report->isValid() ? self::SUCCESS : self::FAILURE;
    }

    private function renderIssues(ValidationReport $report): void
    {
        $errors = $report->errors();
        $warnings = $report->warnings();

        if ($errors === [] && $warnings === []) {
            $this->info('valid');

            return;
        }

        if ($errors !== []) {
            $this->error(sprintf('%d hiba:', count($errors)));
            foreach ($errors as $issue) {
                $this->line("  [{$issue->code}] {$issue->message}");
            }
        } else {
            $this->info('valid (figyelmeztetésekkel)');
        }

        if ($warnings !== []) {
            $this->warn(sprintf('%d figyelmeztetés:', count($warnings)));
            foreach ($warnings as $issue) {
                $this->line("  [{$issue->code}] {$issue->message}");
            }
        }
    }

    private function renderStatistics(ValidationReport $report): void
    {
        $stats = $report->statistics;

        if ($stats === null) {
            return;
        }

        $this->newLine();
        $this->info(sprintf(
            'Válaszkombinációk: %d (%s)',
            $stats->domainSize,
            $stats->sampled ? 'mintavételezve' : 'teljes körűen bejárva',
        ));

        $this->line('Csoport-eloszlás:');
        foreach ($stats->groupHitRates as $groupId => $rate) {
            $this->line(sprintf('  %s: %.1f%%', $groupId, $rate * 100));
        }

        $this->line('Modul-elérés:');
        foreach ($stats->moduleReachRates as $moduleId => $rate) {
            $this->line(sprintf('  %s: %.1f%%', $moduleId, $rate * 100));
        }
    }
}
