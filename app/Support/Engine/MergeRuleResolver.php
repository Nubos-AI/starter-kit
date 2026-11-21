<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\MergeRuleDecision;
use App\Enums\Engine\MergeRuleMode;
use App\Models\CustomRecord;
use App\Models\MergeRule;

class MergeRuleResolver
{
    public function __construct(
        private readonly RecordStateConditionEvaluator $conditions,
    ) {}

    public function resolve(CustomRecord $target, CustomRecord $source): MergeRuleDecision
    {
        foreach ($this->activeRules($target) as $rule) {
            if (!$this->applies($rule, $target, $source)) {
                continue;
            }

            return new MergeRuleDecision(
                $rule->mode,
                $rule,
                $rule->mode === MergeRuleMode::Deny ? $rule->deny_reason : null,
            );
        }

        return new MergeRuleDecision(MergeRuleMode::Allow);
    }

    /**
     * @return iterable<int, MergeRule>
     */
    private function activeRules(CustomRecord $target): iterable
    {
        return MergeRule::query()
            ->where('object_type_id', $target->object_type_id)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }

    private function applies(MergeRule $rule, CustomRecord $target, CustomRecord $source): bool
    {
        $condition = $rule->condition ?? [];

        if ($condition === []) {
            return true;
        }

        $matchesTarget = $this->conditions->matches($target, $condition);
        $matchesSource = $this->conditions->matches($source, $condition);

        return $rule->mode === MergeRuleMode::Deny
            ? $matchesTarget || $matchesSource
            : $matchesTarget && $matchesSource;
    }
}
