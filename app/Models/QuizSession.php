<?php

namespace App\Models;

use App\QuizConfig\ContentHash;
use App\QuizConfig\Engine\EvaluationResult;
use App\QuizConfig\Engine\ModuleRelevanceResult;
use Database\Factories\QuizSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campaign_id', 'config_version_id', 'answers', 'is_preview', 'is_bot_suspected', 'started_at'])]
class QuizSession extends Model
{
    /** @use HasFactory<QuizSessionFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'result' => 'array',
            'is_preview' => 'boolean',
            'is_bot_suspected' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $session) {
            if ($session->isDirty('answers')) {
                $session->answers_hash = ContentHash::compute($session->answers);
            }
        });
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<ConfigVersion, $this>
     */
    public function configVersion(): BelongsTo
    {
        return $this->belongsTo(ConfigVersion::class);
    }

    public function markCompleted(EvaluationResult $result): void
    {
        $this->result = [
            'label_sums' => $result->labelSums,
            'matched_group_ids' => $result->matchedGroupIds,
            'ranked_modules' => array_map(
                fn (ModuleRelevanceResult $module) => [
                    'module_id' => $module->moduleId,
                    'matched' => $module->matched,
                    'score' => $module->score,
                    'relevant' => $module->relevant,
                    'rank' => $module->rank,
                ],
                $result->rankedModules,
            ),
        ];
        $this->completed_at = now();
        $this->save();
    }
}
