<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeRuleMode;
use App\Enums\Engine\MergeTransferCategory;
use App\Enums\Engine\MergeTransferPolicy;
use App\Models\FieldDefinition;
use App\Models\MergeRule;

readonly class MergeRuleDecision
{
    public function __construct(
        public MergeRuleMode $mode,
        public ?MergeRule $rule = null,
        public ?string $denyReason = null,
    ) {}

    public function forbids(): bool
    {
        return $this->mode === MergeRuleMode::Deny;
    }

    public function strategyFor(FieldDefinition $field): MergeFieldStrategy
    {
        $configured = $this->rule?->field_strategies[$field->key] ?? null;

        if (is_string($configured)) {
            $strategy = MergeFieldStrategy::tryFrom($configured);

            if ($strategy instanceof MergeFieldStrategy && $strategy->supports($field->field_type)) {
                return $strategy;
            }
        }

        if ($field->merge_strategy instanceof MergeFieldStrategy && $field->merge_strategy->supports($field->field_type)) {
            return $field->merge_strategy;
        }

        return MergeFieldStrategy::PreferNonEmpty;
    }

    public function policyFor(MergeTransferCategory $category): MergeTransferPolicy
    {
        $configured = $this->rule?->transfer_policy[$category->value] ?? null;

        if (is_string($configured)) {
            $policy = MergeTransferPolicy::tryFrom($configured);

            if ($policy instanceof MergeTransferPolicy && $category->allows($policy)) {
                return $policy;
            }
        }

        return $category->defaultPolicy();
    }

    public function requiresReason(): bool
    {
        return $this->option('requires_reason');
    }

    public function requiresDedupMatch(): bool
    {
        return $this->option('requires_dedup_match');
    }

    public function inheritsExternalReference(): bool
    {
        return $this->option('inherits_external_reference');
    }

    public function blocksOnRunningAutomations(): bool
    {
        return $this->option('blocks_on_running_automations');
    }

    public function option(string $key): bool
    {
        return $this->rule?->options[$key] ?? false;
    }
}
