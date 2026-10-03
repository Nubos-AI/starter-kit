<?php

declare(strict_types=1);

namespace App\Http\Resources\Aging;

use App\Enums\Engine\AgingRuleActionRefusalReason;
use App\Models\AgingRule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgingRule
 */
class AgingRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AgingRule $agingRule */
        $agingRule = $this->resource;

        $user = $request->user();

        $canUpdate = $user instanceof User && $user->can('update', $agingRule);
        $canDelete = $user instanceof User && $user->can('delete', $agingRule);

        return [
            'id' => (string) $agingRule->getKey(),
            'object_type_id' => $agingRule->object_type_id,
            'name' => $agingRule->name,
            'clock' => $agingRule->clock->value,
            'clock_field_key' => $agingRule->clock_field_key,
            'condition' => $agingRule->condition ?? [],
            'thresholds' => $agingRule->thresholds,
            'is_active' => $agingRule->is_active,
            'triggers_automation' => $agingRule->triggers_automation,
            'can_update' => $canUpdate,
            'can_delete' => $canDelete,
            'update_reason' => $this->refusalReason($canUpdate)?->value,
            'delete_reason' => $this->refusalReason($canDelete)?->value,
            'updated_at' => $agingRule->updated_at?->toIso8601String(),
        ];
    }

    private function refusalReason(bool $isAllowed): ?AgingRuleActionRefusalReason
    {
        return $isAllowed ? null : AgingRuleActionRefusalReason::NotPermitted;
    }
}
