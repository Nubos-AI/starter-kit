<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Enums\Engine\MergeRuleActionRefusalReason;
use App\Models\MergeRule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MergeRule
 */
class MergeRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MergeRule $mergeRule */
        $mergeRule = $this->resource;

        $user = $request->user();

        $canUpdate = $user instanceof User && $user->can('update', $mergeRule);
        $canDelete = $user instanceof User && $user->can('delete', $mergeRule);

        return [
            'id' => (string) $mergeRule->getKey(),
            'object_type_id' => $mergeRule->object_type_id,
            'name' => $mergeRule->name,
            'mode' => $mergeRule->mode->value,
            'position' => $mergeRule->position,
            'is_active' => $mergeRule->is_active,
            'deny_reason' => $mergeRule->deny_reason,
            'condition' => $mergeRule->condition ?? [],
            'field_strategies' => $mergeRule->field_strategies,
            'transfer_policy' => $mergeRule->transfer_policy,
            'options' => $mergeRule->options,
            'can_update' => $canUpdate,
            'can_delete' => $canDelete,
            'update_reason' => $this->refusalReason($canUpdate)?->value,
            'delete_reason' => $this->refusalReason($canDelete)?->value,
            'updated_at' => $mergeRule->updated_at?->toIso8601String(),
        ];
    }

    private function refusalReason(bool $isAllowed): ?MergeRuleActionRefusalReason
    {
        return $isAllowed ? null : MergeRuleActionRefusalReason::NotPermitted;
    }
}
