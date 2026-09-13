<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\QuizEvent;
use Illuminate\Console\Command;

class ExportQuizEvents extends Command
{
    protected $signature = 'quiz-events:export {campaign : Campaign slug} {--output= : Output file path (defaults to stdout)}';

    protected $description = 'Export a campaign\'s quiz event log to CSV';

    public function handle(): int
    {
        $campaign = Campaign::where('slug', $this->argument('campaign'))->first();

        if ($campaign === null) {
            $this->error("Campaign not found: {$this->argument('campaign')}");

            return self::FAILURE;
        }

        $output = $this->option('output');
        $handle = $output ? fopen($output, 'w') : fopen('php://stdout', 'w');

        fputcsv($handle, ['id', 'quiz_session_id', 'config_version_id', 'type', 'metadata', 'created_at']);

        QuizEvent::whereIn('config_version_id', $campaign->configVersions()->pluck('id'))
            ->orderBy('created_at')
            ->chunk(500, function ($events) use ($handle) {
                foreach ($events as $event) {
                    fputcsv($handle, [
                        $event->id,
                        $event->quiz_session_id,
                        $event->config_version_id,
                        $event->type->value,
                        json_encode($event->metadata),
                        $event->created_at->toIso8601String(),
                    ]);
                }
            });

        fclose($handle);

        if ($output) {
            $this->info("Exported to {$output}");
        }

        return self::SUCCESS;
    }
}
