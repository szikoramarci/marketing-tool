<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\ConfigVersion;
use App\QuizConfig\ContentHash;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfigVersion>
 */
class ConfigVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = $this->minimalValidContent();

        return [
            'campaign_id' => Campaign::factory(),
            'status' => 'draft',
            'content' => $content,
            'content_hash' => ContentHash::compute($content),
            'traffic_weight' => 100,
            'created_by' => fake()->email(),
        ];
    }

    /**
     * A schema-shape-valid content payload (the DB-column subset, without the
     * status/created_at/created_by/version_id/content_hash metadata that
     * ConfigVersion::toConfigObject() supplies separately) — just enough to pass
     * ConfigSchemaValidator, not necessarily to pass the full combinatorial validator.
     *
     * @return array<string, mixed>
     */
    private function minimalValidContent(): array
    {
        return [
            'locale' => 'hu',
            'experiment_compatible_with_previous' => false,
            'generation_trace' => null,
            'labels' => [
                ['id' => 'label_a', 'name' => 'Label A', 'axis' => 'content'],
            ],
            'questions' => [
                [
                    'id' => 'q1',
                    'text' => 'Melyik igaz rád?',
                    'input_type' => 'single',
                    'required' => true,
                    'options' => [
                        ['id' => 'q1_o1', 'text' => 'A', 'label_weights' => ['label_a' => 1]],
                        ['id' => 'q1_o2', 'text' => 'B', 'label_weights' => []],
                    ],
                ],
            ],
            'evaluation_groups' => [
                [
                    'id' => 'group_fallback',
                    'name' => 'Fallback',
                    'priority' => 1,
                    'is_fallback' => true,
                    'allow_multiple' => false,
                    'rule' => null,
                    'result_page' => [
                        'title' => 'Eredmény',
                        'summary' => 'Összefoglaló.',
                        'sections' => [],
                        'video_url' => 'https://videos.example.com/fallback.mp4',
                        'cta' => [
                            'email_capture' => ['headline' => 'Kérd el emailben', 'body' => 'Küldünk egy összefoglalót.'],
                            'consultation_offer_emphasis' => 'hidden',
                        ],
                    ],
                ],
            ],
            'modules' => [
                [
                    'id' => 'module_a',
                    'name' => 'Module A',
                    'relevance_rule' => [
                        'type' => 'comparison',
                        'operator' => 'gte',
                        'left' => ['type' => 'label_sum', 'label' => 'label_a'],
                        'right' => ['type' => 'constant', 'value' => 1],
                    ],
                    'relevance_score' => 1,
                    'threshold' => 0,
                    'email_content_ref' => 'module_a_email',
                ],
            ],
            'module_settings' => ['max_recommended_modules' => 1],
            'emails' => [
                'sequences' => [],
                'default_sequence' => [
                    'id' => 'seq_default',
                    'steps' => [
                        ['kind' => 'fixed', 'delay_hours' => 0, 'subject' => 'Eredmény', 'body' => 'Az eredményed.'],
                    ],
                ],
                'shared_blocks' => [],
                'module_content' => [
                    'module_a_email' => ['subject' => 'Modul tartalom', 'body' => 'Részletek.'],
                ],
            ],
        ];
    }
}
