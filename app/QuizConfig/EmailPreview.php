<?php

namespace App\QuizConfig;

/**
 * Resolves a config's email content for display, without a real session/ranking —
 * fixed and shared_block steps resolve to their literal subject/body; module_driven
 * steps can't (which module lands at rank N depends on that session's answers), so
 * they're shown as a reference into the separate per-module content list instead.
 */
class EmailPreview
{
    /**
     * @return array{sequences: array<int, array>, default_sequence: array, modules: array<int, array>}
     */
    public static function resolve(object $config): array
    {
        $sharedBlocks = (array) $config->emails->shared_blocks;
        $moduleContent = (array) $config->emails->module_content;

        return [
            'sequences' => array_map(
                fn (object $sequence) => self::resolveSequence($sequence, $sharedBlocks),
                $config->emails->sequences,
            ),
            'default_sequence' => self::resolveSequence($config->emails->default_sequence, $sharedBlocks),
            'modules' => array_map(
                fn (object $module) => self::resolveModule($module, $moduleContent),
                $config->modules,
            ),
        ];
    }

    /**
     * @param  array<string, object>  $sharedBlocks
     */
    private static function resolveSequence(object $sequence, array $sharedBlocks): array
    {
        return [
            'id' => $sequence->id,
            'applies_to_groups' => $sequence->applies_to_groups ?? null,
            'steps' => array_map(
                fn (object $step) => self::resolveStep($step, $sharedBlocks),
                $sequence->steps,
            ),
        ];
    }

    /**
     * @param  array<string, object>  $sharedBlocks
     */
    private static function resolveStep(object $step, array $sharedBlocks): array
    {
        return match ($step->kind) {
            'fixed' => [
                'kind' => 'fixed',
                'delay_hours' => $step->delay_hours,
                'subject' => $step->subject,
                'body' => $step->body,
            ],
            'shared_block' => [
                'kind' => 'shared_block',
                'delay_hours' => $step->delay_hours,
                'subject' => $sharedBlocks[$step->block_ref]->subject ?? null,
                'body' => $sharedBlocks[$step->block_ref]->body ?? null,
            ],
            'module_driven' => [
                'kind' => 'module_driven',
                'delay_hours' => $step->delay_hours,
                'module_rank' => $step->module_rank,
            ],
        };
    }

    /**
     * @param  array<string, object>  $moduleContent
     */
    private static function resolveModule(object $module, array $moduleContent): array
    {
        $content = $moduleContent[$module->email_content_ref] ?? null;

        return [
            'id' => $module->id,
            'name' => $module->name,
            'subject' => $content->subject ?? null,
            'body' => $content->body ?? null,
        ];
    }
}
