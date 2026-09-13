<?php

namespace App\Models;

use App\QuizConfig\ConfigSchemaValidator;
use App\QuizConfig\ContentHash;
use App\States\ConfigVersionStatus\ConfigVersionStatus;
use Database\Factories\ConfigVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['campaign_id', 'status', 'content', 'content_hash', 'traffic_weight', 'created_by'])]
class ConfigVersion extends Model
{
    /** @use HasFactory<ConfigVersionFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'status' => ConfigVersionStatus::class,
            'traffic_weight' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return HasMany<QuizSession, $this>
     */
    public function quizSessions(): HasMany
    {
        return $this->hasMany(QuizSession::class);
    }

    /**
     * Creates a new draft version from a full candidate document (the shape
     * ConfigSchemaValidator/EvaluationEngine/ConfigValidator expect). Only checks shape —
     * the fuller combinatorial validation is the activation transition's job.
     */
    public static function createFromDocument(Campaign $campaign, object $document, string $createdBy): self
    {
        if (($document->status ?? null) !== 'draft') {
            throw new InvalidArgumentException('New config versions must be created with status "draft".');
        }

        $content = self::validatedContentFromDocument($document);

        $configVersion = new self([
            'status' => 'draft',
            'content' => $content,
            'content_hash' => ContentHash::compute($content),
            'created_by' => $createdBy,
        ]);

        $configVersion->campaign()->associate($campaign);
        $configVersion->save();

        return $configVersion;
    }

    /**
     * Only a draft's content may be edited in place — "a publikált verzió tartalma soha
     * nem módosul" applies from the moment a version first leaves draft. Use
     * cloneAsNewDraft() to change a published version's content.
     */
    public function updateDraftDocument(object $document): void
    {
        if ($this->status->getMorphClass() !== 'draft') {
            throw new InvalidArgumentException('Only draft config versions can have their content edited in place.');
        }

        $content = self::validatedContentFromDocument($document);

        $this->content = $content;
        $this->content_hash = ContentHash::compute($content);
        $this->save();
    }

    /**
     * The only way to change a non-draft version's content: a fresh draft row carrying the
     * same content, free to be edited from there.
     */
    public function cloneAsNewDraft(string $createdBy): self
    {
        $clone = new self([
            'campaign_id' => $this->campaign_id,
            'status' => 'draft',
            'content' => $this->content,
            'content_hash' => $this->content_hash,
            'created_by' => $createdBy,
        ]);

        $clone->save();

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    private static function validatedContentFromDocument(object $document): array
    {
        $validation = (new ConfigSchemaValidator)->validate($document);

        if (! $validation->valid) {
            $messages = implode('; ', array_map(fn ($error) => (string) $error, $validation->errors));

            throw new InvalidArgumentException("Invalid config document: {$messages}");
        }

        $content = (array) json_decode(json_encode($document), true);
        unset(
            $content['version_id'],
            $content['content_hash'],
            $content['created_at'],
            $content['created_by'],
            $content['status'],
        );

        return $content;
    }

    /**
     * Object-typed (additionalProperties) maps in the schema — label_weights, shared_blocks,
     * module_content. Everything else that's array-typed in the schema is a genuine list.
     */
    private const array OBJECT_MAP_KEYS = ['label_weights', 'shared_blocks', 'module_content'];

    /**
     * Reassembles the full document shape from DB metadata + the stored semantic content.
     */
    public function toConfigObject(): object
    {
        $document = array_merge($this->content, [
            'version_id' => $this->id,
            'content_hash' => $this->content_hash,
            'created_at' => $this->created_at->toIso8601String(),
            'created_by' => $this->created_by,
            'status' => $this->status->getMorphClass(),
        ]);

        return json_decode(json_encode(self::restoreEmptyObjectMaps($document)));
    }

    /**
     * The DB's `array` cast round-trips JSON through PHP arrays, which can't distinguish
     * an empty object ("{}") from an empty list ("[]") — every empty value comes back as
     * a plain [], which json_encode always renders as "[]". Schema-object-typed maps that
     * happen to be empty need to be restored to stdClass so they re-encode correctly.
     */
    private static function restoreEmptyObjectMaps(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::restoreEmptyObjectMaps(...), $value);
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $item = self::restoreEmptyObjectMaps($item);

            if (in_array($key, self::OBJECT_MAP_KEYS, true) && $item === []) {
                $item = new \stdClass;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
