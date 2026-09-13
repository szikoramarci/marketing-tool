<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\QuizConfig\Analytics\FunnelQueries;
use Illuminate\Console\Command;

class QuizEventsReport extends Command
{
    protected $signature = 'quiz-events:report {campaign : Campaign slug}';

    protected $description = 'Print the funnel/dropout/group-distribution queries for a campaign';

    public function handle(FunnelQueries $queries): int
    {
        $campaign = Campaign::where('slug', $this->argument('campaign'))->first();

        if ($campaign === null) {
            $this->error("Campaign not found: {$this->argument('campaign')}");

            return self::FAILURE;
        }

        $this->info('Kitöltési tölcsér variánsonként:');
        $this->table(
            ['config_version_id', 'started', 'completed', 'completion_rate'],
            $queries->completionFunnelByVariant($campaign)->map(fn ($row) => [
                $row['config_version_id'],
                $row['started'],
                $row['completed'],
                sprintf('%.1f%%', $row['completion_rate'] * 100),
            ]),
        );

        $this->newLine();
        $this->info('Kiesés kérdésenként:');
        $this->table(
            ['config_version_id', 'question_id', 'position', 'normalized_position', 'shown_count'],
            $queries->dropoutByQuestion($campaign)->map(fn ($row) => [
                $row['config_version_id'],
                $row['question_id'],
                $row['position'],
                sprintf('%.2f', $row['normalized_position']),
                $row['shown_count'],
            ]),
        );

        $this->newLine();
        $this->info('Csoporteloszlás:');
        $this->table(
            ['config_version_id', 'group_id', 'count'],
            $queries->groupDistribution($campaign)->map(fn ($row) => [
                $row['config_version_id'],
                $row['group_id'],
                $row['count'],
            ]),
        );

        return self::SUCCESS;
    }
}
