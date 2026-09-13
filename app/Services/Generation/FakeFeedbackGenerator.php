<?php

namespace App\Services\Generation;

use App\Contracts\FeedbackGenerator;
use App\Contracts\GenerationRequest;
use App\Contracts\GenerationResult;
use stdClass;

/**
 * Deterministic, no network — the default driver everywhere (local, tests), per
 * CLAUDE.md's convention for every external-service contract.
 */
class FakeFeedbackGenerator implements FeedbackGenerator
{
    public function generate(GenerationRequest $request): GenerationResult
    {
        $document = [
            'version_id' => 'fake-generated',
            'content_hash' => 'pending',
            'created_at' => now()->toIso8601String(),
            'created_by' => 'fake-feedback-generator',
            'status' => 'draft',
            'locale' => $request->locale,
            'experiment_compatible_with_previous' => false,
            'generation_trace' => [
                'source_reference' => $request->sourceReference,
                'instructions' => $request->instructions,
                'model' => 'fake',
            ],
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
                        ['id' => 'q1_o2', 'text' => 'B', 'label_weights' => new stdClass],
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
                'shared_blocks' => new stdClass,
                'module_content' => [
                    'module_a_email' => ['subject' => 'Modul tartalom', 'body' => 'Részletek.'],
                ],
            ],
        ];

        return new GenerationResult(
            documentJson: json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            model: 'fake',
        );
    }
}
