<?php

namespace App\QuizConfig\Validation;

use App\QuizConfig\ConfigSchemaValidator;
use App\QuizConfig\Engine\EvaluationEngine;

class ConfigValidator
{
    private const float DOMINANCE_THRESHOLD = 0.7;

    private const float NEGLIGIBLE_THRESHOLD = 0.02;

    private const float FALLBACK_DOMINANCE_THRESHOLD = 0.9;

    private const int SAMPLE_SIZE = 3000;

    public function __construct(
        private readonly ConfigSchemaValidator $schemaValidator = new ConfigSchemaValidator,
        private readonly EvaluationEngine $engine = new EvaluationEngine,
        private readonly AnswerDomain $domain = new AnswerDomain,
    ) {}

    public function validate(object $config, ?int $sampleSizeOverride = null): ValidationReport
    {
        $schemaResult = $this->schemaValidator->validate($config);

        if (! $schemaResult->valid) {
            $issues = array_map(
                fn ($error) => new ValidationIssue(IssueSeverity::Error, 'schema', (string) $error),
                $schemaResult->errors,
            );

            return new ValidationReport($issues);
        }

        $issues = [
            ...$this->checkReferentialIntegrity($config),
            ...$this->checkFallbackCount($config),
            ...$this->checkEmptyResultBlocks($config),
            ...$this->checkResultPageModuleLeaks($config),
        ];

        // The combinatorial pass runs the evaluation engine, which assumes a referentially
        // valid config (e.g. every module/label reference resolves) — running it over a
        // config already known to violate that would crash rather than report cleanly.
        $hasBlockingErrors = array_any($issues, fn (ValidationIssue $issue) => $issue->severity === IssueSeverity::Error);

        if ($hasBlockingErrors) {
            return new ValidationReport($issues);
        }

        [$combinatorialIssues, $statistics] = $this->runCombinatorialChecks($config, $sampleSizeOverride);

        return new ValidationReport([...$issues, ...$combinatorialIssues], $statistics);
    }

    /**
     * @return ValidationIssue[]
     */
    private function checkReferentialIntegrity(object $config): array
    {
        $issues = [];

        $labelIds = array_map(fn (object $label) => $label->id, $config->labels);
        $moduleIds = array_map(fn (object $module) => $module->id, $config->modules);
        $groupIds = array_map(fn (object $group) => $group->id, $config->evaluation_groups);

        $questionOptions = [];
        foreach ($config->questions as $question) {
            $questionOptions[$question->id] = array_map(fn (object $option) => $option->id, $question->options);
        }

        $context = ['labelIds' => $labelIds, 'questionOptions' => $questionOptions, 'moduleIds' => $moduleIds];

        foreach ($config->questions as $question) {
            foreach ($question->options as $option) {
                foreach (array_keys((array) $option->label_weights) as $labelId) {
                    if (! in_array($labelId, $labelIds, true)) {
                        $issues[] = new ValidationIssue(
                            IssueSeverity::Error,
                            'unknown-label-reference',
                            "A(z) „{$question->id}/{$option->id}” válaszopció egy nem létező címkéhez rendel súlyt: „{$labelId}”.",
                        );
                    }
                }
            }
        }

        foreach ($config->evaluation_groups as $group) {
            if ($group->rule !== null) {
                $this->walkRuleNode($group->rule, "evaluation_groups/{$group->id}", $context, $issues);
            }
        }

        foreach ($config->modules as $module) {
            $this->walkRuleNode($module->relevance_rule, "modules/{$module->id}", $context, $issues);
        }

        $sharedBlockIds = array_keys((array) $config->emails->shared_blocks);
        $moduleContentIds = array_keys((array) $config->emails->module_content);

        foreach ($config->modules as $module) {
            if (! in_array($module->email_content_ref, $moduleContentIds, true)) {
                $issues[] = new ValidationIssue(
                    IssueSeverity::Error,
                    'missing-module-content',
                    "A(z) „{$module->id}” modulhoz nincs email-tartalom rendelve: „{$module->email_content_ref}”.",
                );
            }
        }

        foreach ([...$config->emails->sequences, $config->emails->default_sequence] as $sequence) {
            foreach ($sequence->applies_to_groups ?? [] as $groupId) {
                if (! in_array($groupId, $groupIds, true)) {
                    $issues[] = new ValidationIssue(
                        IssueSeverity::Error,
                        'unknown-group-reference',
                        "A(z) „{$sequence->id}” email-sorozat egy nem létező csoportra hivatkozik: „{$groupId}”.",
                    );
                }
            }

            foreach ($sequence->steps as $step) {
                if ($step->kind === 'shared_block' && ! in_array($step->block_ref, $sharedBlockIds, true)) {
                    $issues[] = new ValidationIssue(
                        IssueSeverity::Error,
                        'unknown-shared-block-reference',
                        "A(z) „{$sequence->id}” email-sorozat egy nem létező megosztott blokkra hivatkozik: „{$step->block_ref}”.",
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array{labelIds: array<int, string>, questionOptions: array<string, array<int, string>>, moduleIds: array<int, string>}  $context
     * @param  ValidationIssue[]  $issues
     */
    private function walkRuleNode(object $node, string $where, array $context, array &$issues): void
    {
        switch ($node->type) {
            case 'label_sum':
                if (! in_array($node->label, $context['labelIds'], true)) {
                    $issues[] = new ValidationIssue(
                        IssueSeverity::Error,
                        'unknown-label-reference',
                        "A(z) „{$where}” szabály egy nem létező címkére hivatkozik: „{$node->label}”.",
                    );
                }
                break;

            case 'constant':
                break;

            case 'answer_selected':
                if (! isset($context['questionOptions'][$node->question])) {
                    $issues[] = new ValidationIssue(
                        IssueSeverity::Error,
                        'unknown-question-reference',
                        "A(z) „{$where}” szabály egy nem létező kérdésre hivatkozik: „{$node->question}”.",
                    );
                } elseif (! in_array($node->option, $context['questionOptions'][$node->question], true)) {
                    $issues[] = new ValidationIssue(
                        IssueSeverity::Error,
                        'unknown-option-reference',
                        "A(z) „{$where}” szabály egy nem létező válaszopcióra hivatkozik: „{$node->option}” (kérdés: „{$node->question}”).",
                    );
                }
                break;

            case 'module_relevant':
            case 'module_top_rank':
                if (! in_array($node->module, $context['moduleIds'], true)) {
                    $issues[] = new ValidationIssue(
                        IssueSeverity::Error,
                        'unknown-module-reference',
                        "A(z) „{$where}” szabály egy nem létező modulra hivatkozik: „{$node->module}”.",
                    );
                }
                break;

            case 'comparison':
                $this->walkRuleNode($node->left, $where, $context, $issues);
                $this->walkRuleNode($node->right, $where, $context, $issues);
                break;

            case 'and':
            case 'or':
                foreach ($node->operands as $operand) {
                    $this->walkRuleNode($operand, $where, $context, $issues);
                }
                break;

            case 'not':
                $this->walkRuleNode($node->operand, $where, $context, $issues);
                break;
        }
    }

    /**
     * @return ValidationIssue[]
     */
    private function checkFallbackCount(object $config): array
    {
        $fallbackCount = count(array_filter($config->evaluation_groups, fn (object $group) => $group->is_fallback));

        if ($fallbackCount > 1) {
            return [new ValidationIssue(
                IssueSeverity::Error,
                'multiple-fallback-groups',
                "Több ({$fallbackCount}) tartalék (fallback) csoport van megadva, pontosan egynek kell lennie.",
            )];
        }

        return [];
    }

    /**
     * @return ValidationIssue[]
     */
    private function checkEmptyResultBlocks(object $config): array
    {
        $issues = [];

        foreach ($config->evaluation_groups as $group) {
            if ($group->result_page->sections === []) {
                $issues[] = new ValidationIssue(
                    IssueSeverity::Warning,
                    'empty-result-sections',
                    "A(z) „{$group->id}” csoport eredményoldalán nincs egyetlen részletező szakasz sem.",
                );
            }
        }

        return $issues;
    }

    /**
     * @return ValidationIssue[]
     */
    private function checkResultPageModuleLeaks(object $config): array
    {
        $issues = [];

        foreach ($config->evaluation_groups as $group) {
            foreach ($this->resultPageTexts($group->result_page) as $label => $text) {
                foreach ($config->modules as $module) {
                    foreach (['id' => $module->id, 'name' => $module->name] as $what => $needle) {
                        if (str_contains($text, $needle)) {
                            $whatLabel = $what === 'id' ? 'azonosítóját' : 'nevét';
                            $issues[] = new ValidationIssue(
                                IssueSeverity::Error,
                                'module-reference-leak',
                                "A(z) „{$group->id}” csoport eredményoldalának „{$label}” mezője tartalmazza a(z) „{$module->id}” modul {$whatLabel} („{$needle}”) — ez belső adat, nem jelenhet meg a kitöltőnek.",
                            );
                        }
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @return array<string, string>
     */
    private function resultPageTexts(object $resultPage): array
    {
        $texts = [
            'title' => $resultPage->title,
            'summary' => $resultPage->summary,
            'cta.email_capture.headline' => $resultPage->cta->email_capture->headline,
            'cta.email_capture.body' => $resultPage->cta->email_capture->body,
        ];

        foreach ($resultPage->sections as $i => $section) {
            $texts["sections[{$i}].title"] = $section->title;
            $texts["sections[{$i}].body"] = $section->body;
        }

        return $texts;
    }

    /**
     * @return array{0: ValidationIssue[], 1: ValidationStatistics}
     */
    private function runCombinatorialChecks(object $config, ?int $sampleSizeOverride = null): array
    {
        $domainSize = $this->domain->size($config);
        $sampled = $sampleSizeOverride !== null || $this->domain->shouldSample($config);
        $sampleSize = $sampleSizeOverride ?? self::SAMPLE_SIZE;

        $vectors = $sampled
            ? $this->domain->sample($config, $sampleSize)
            : $this->domain->enumerate($config);

        $total = $sampled ? $sampleSize : $domainSize;

        $groupIds = array_map(fn (object $group) => $group->id, $config->evaluation_groups);
        $fallbackGroup = current(array_filter($config->evaluation_groups, fn (object $group) => $group->is_fallback));
        $moduleIds = array_map(fn (object $module) => $module->id, $config->modules);
        $maxRecommended = $config->module_settings->max_recommended_modules;

        $groupPrimaryHits = array_fill_keys($groupIds, 0);
        $moduleRelevantHits = array_fill_keys($moduleIds, 0);
        $moduleTopRankHits = array_fill_keys($moduleIds, 0);

        foreach ($vectors as $answers) {
            $result = $this->engine->evaluate($config, $answers);

            $groupPrimaryHits[$result->matchedGroupIds[0]]++;

            foreach ($result->rankedModules as $moduleResult) {
                if (! $moduleResult->relevant) {
                    continue;
                }

                $moduleRelevantHits[$moduleResult->moduleId]++;

                if ($moduleResult->rank <= $maxRecommended) {
                    $moduleTopRankHits[$moduleResult->moduleId]++;
                }
            }
        }

        // PHP's `/` returns int, not float, when the division is exact (e.g. 0 / 189) —
        // cast explicitly so `=== 0.0` below is reliable regardless of the numerator.
        $rate = fn (int $count) => $total > 0 ? (float) $count / $total : 0.0;

        $groupHitRates = array_map($rate, $groupPrimaryHits);
        $moduleReachRates = array_map($rate, $moduleRelevantHits);
        $fallbackRate = $groupHitRates[$fallbackGroup->id] ?? 0.0;

        $statistics = new ValidationStatistics($domainSize, $sampled, $groupHitRates, $moduleReachRates, $fallbackRate);

        $issues = [
            ...$this->groupBalanceIssues($config, $groupHitRates),
            ...$this->moduleReachIssues($config, $moduleReachRates, $moduleTopRankHits, $maxRecommended),
        ];

        if ($fallbackRate > self::FALLBACK_DOMINANCE_THRESHOLD) {
            $issues[] = new ValidationIssue(
                IssueSeverity::Warning,
                'fallback-dominant',
                sprintf('A válaszkombinációk %.0f%%-a a tartalék (fallback) csoportba esik — szinte semmi sem kerül ténylegesen besorolásra.', $fallbackRate * 100),
            );
        }

        return [$issues, $statistics];
    }

    /**
     * @param  array<string, float>  $groupHitRates
     * @return ValidationIssue[]
     */
    private function groupBalanceIssues(object $config, array $groupHitRates): array
    {
        $issues = [];

        foreach ($config->evaluation_groups as $group) {
            if ($group->is_fallback) {
                continue;
            }

            $rate = $groupHitRates[$group->id];

            if ($rate === 0.0) {
                $issues[] = new ValidationIssue(
                    IssueSeverity::Error,
                    'unreachable-group',
                    "A(z) „{$group->id}” csoport egyetlen lehetséges válasszal sem érhető el.",
                );
            } elseif ($rate > self::DOMINANCE_THRESHOLD) {
                $issues[] = new ValidationIssue(
                    IssueSeverity::Warning,
                    'dominant-group',
                    sprintf('A(z) „%s” csoport aránytalanul gyakran (a válaszkombinációk %.0f%%-ában) kerül kiválasztásra.', $group->id, $rate * 100),
                );
            } elseif ($rate < self::NEGLIGIBLE_THRESHOLD) {
                $issues[] = new ValidationIssue(
                    IssueSeverity::Warning,
                    'negligible-group',
                    sprintf('A(z) „%s” csoport csak elenyésző eséllyel (a válaszkombinációk %.1f%%-ában) kerül kiválasztásra.', $group->id, $rate * 100),
                );
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, float>  $moduleReachRates
     * @param  array<string, int>  $moduleTopRankHits
     * @return ValidationIssue[]
     */
    private function moduleReachIssues(object $config, array $moduleReachRates, array $moduleTopRankHits, int $maxRecommended): array
    {
        $issues = [];

        foreach ($config->modules as $module) {
            $reach = $moduleReachRates[$module->id];

            if ($reach === 0.0) {
                $issues[] = new ValidationIssue(
                    IssueSeverity::Error,
                    'unreachable-module',
                    "A(z) „{$module->id}” modul egyetlen lehetséges válasszal sem lesz releváns (a küszöb sosem teljesül).",
                );
            } elseif ($moduleTopRankHits[$module->id] === 0) {
                $issues[] = new ValidationIssue(
                    IssueSeverity::Warning,
                    'module-never-in-top-rank',
                    "A(z) „{$module->id}” modul néha releváns, de a rangsorban sosem kerül az első {$maxRecommended} közé — tartalma valójában sosem kerülne kiküldésre.",
                );
            }
        }

        return $issues;
    }
}
