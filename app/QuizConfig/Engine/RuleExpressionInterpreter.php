<?php

namespace App\QuizConfig\Engine;

use InvalidArgumentException;

class RuleExpressionInterpreter
{
    public function evaluateBoolean(object $node, EvaluationContext $context): bool
    {
        return match ($node->type) {
            'comparison' => $this->evaluateComparison($node, $context),
            'answer_selected' => $context->answers->hasSelected($node->question, $node->option),
            'module_relevant' => $context->moduleResult($node->module)->relevant,
            'module_top_rank' => $this->evaluateModuleTopRank($node, $context),
            'and' => $this->evaluateAnd($node, $context),
            'or' => $this->evaluateOr($node, $context),
            'not' => ! $this->evaluateBoolean($node->operand, $context),
            default => throw new InvalidArgumentException("Unknown rule expression node type: '{$node->type}'."),
        };
    }

    public function evaluateNumeric(object $node, EvaluationContext $context): float
    {
        return match ($node->type) {
            'label_sum' => $context->labelSum($node->label),
            'constant' => (float) $node->value,
            default => throw new InvalidArgumentException("Unknown numeric expression node type: '{$node->type}'."),
        };
    }

    private function evaluateComparison(object $node, EvaluationContext $context): bool
    {
        $left = $this->evaluateNumeric($node->left, $context);
        $right = $this->evaluateNumeric($node->right, $context);

        return match ($node->operator) {
            'eq' => $left === $right,
            'ne' => $left !== $right,
            'lt' => $left < $right,
            'lte' => $left <= $right,
            'gt' => $left > $right,
            'gte' => $left >= $right,
            default => throw new InvalidArgumentException("Unknown comparison operator: '{$node->operator}'."),
        };
    }

    private function evaluateModuleTopRank(object $node, EvaluationContext $context): bool
    {
        $result = $context->moduleResult($node->module);

        return $result->relevant && $result->rank !== null && $result->rank <= $node->within;
    }

    private function evaluateAnd(object $node, EvaluationContext $context): bool
    {
        foreach ($node->operands as $operand) {
            if (! $this->evaluateBoolean($operand, $context)) {
                return false;
            }
        }

        return true;
    }

    private function evaluateOr(object $node, EvaluationContext $context): bool
    {
        foreach ($node->operands as $operand) {
            if ($this->evaluateBoolean($operand, $context)) {
                return true;
            }
        }

        return false;
    }
}
