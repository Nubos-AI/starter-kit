<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\MergeRuleDecision;
use App\Enums\Engine\MergeTransferCategory;
use App\Enums\Engine\MergeTransferPolicy;
use Illuminate\Database\Eloquent\Model;

class MergeTransferCategories
{
    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return [
            ...array_map(static fn (MergeTransferCategory $category): string => $category->value, MergeTransferCategory::cases()),
            ...array_keys($this->moduleCategories()),
        ];
    }

    public function exists(string $category): bool
    {
        return in_array($category, $this->keys(), true);
    }

    public function defaultPolicy(string $category): MergeTransferPolicy
    {
        $core = MergeTransferCategory::tryFrom($category);

        if ($core instanceof MergeTransferCategory) {
            return $core->defaultPolicy();
        }

        return MergeTransferPolicy::tryFrom((string) ($this->moduleCategories()[$category]['default_policy'] ?? '')) ?? MergeTransferPolicy::Keep;
    }

    /**
     * @return list<MergeTransferPolicy>
     */
    public function allowedPolicies(string $category): array
    {
        $core = MergeTransferCategory::tryFrom($category);

        return $core instanceof MergeTransferCategory ? $core->allowedPolicies() : MergeTransferPolicy::cases();
    }

    public function allows(string $category, MergeTransferPolicy $policy): bool
    {
        return in_array($policy, $this->allowedPolicies($category), true);
    }

    public function policyFor(MergeRuleDecision $decision, string $category): MergeTransferPolicy
    {
        $configured = $decision->rule?->transfer_policy[$category] ?? null;

        if (is_string($configured)) {
            $policy = MergeTransferPolicy::tryFrom($configured);

            if ($policy instanceof MergeTransferPolicy && $this->allows($category, $policy)) {
                return $policy;
            }
        }

        return $this->defaultPolicy($category);
    }

    /**
     * @return class-string<Model>|null
     */
    public function moduleModel(string $category): ?string
    {
        $model = $this->moduleCategories()[$category]['model'] ?? null;

        return is_string($model) && is_subclass_of($model, Model::class) ? $model : null;
    }

    public function deletesOnDiscard(string $category): bool
    {
        return ($this->moduleCategories()[$category]['deletes_on_discard'] ?? false) === true;
    }

    /**
     * @return array<string, array{model?: string, default_policy?: string, deletes_on_discard?: bool}>
     */
    private function moduleCategories(): array
    {
        $configured = config('modules.merge.transfer_categories', []);

        return is_array($configured) ? $configured : [];
    }
}
