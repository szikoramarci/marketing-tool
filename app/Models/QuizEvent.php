<?php

namespace App\Models;

use App\QuizConfig\Analytics\QuizEventType;
use Database\Factories\QuizEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['quiz_session_id', 'config_version_id', 'type', 'metadata'])]
class QuizEvent extends Model
{
    /** @use HasFactory<QuizEventFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QuizEventType::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<QuizSession, $this>
     */
    public function quizSession(): BelongsTo
    {
        return $this->belongsTo(QuizSession::class);
    }

    /**
     * @return BelongsTo<ConfigVersion, $this>
     */
    public function configVersion(): BelongsTo
    {
        return $this->belongsTo(ConfigVersion::class);
    }
}
