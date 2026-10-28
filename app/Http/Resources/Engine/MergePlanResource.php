<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\DTOs\Engine\MergeBlocker;
use App\DTOs\Engine\MergeFieldPlan;
use App\DTOs\Engine\MergePlanData;
use App\DTOs\Engine\MergeTransferPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MergePlanData
 */
class MergePlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MergePlanData $plan */
        $plan = $this->resource;

        return [
            'target_id' => $plan->targetId,
            'source_id' => $plan->sourceId,
            'target_version' => $plan->targetVersion,
            'source_version' => $plan->sourceVersion,
            'mode' => $plan->mode->value,
            'rule_id' => $plan->ruleId,
            'rule_name' => $plan->ruleName,
            'deny_reason' => $plan->denyReason,
            'requires_reason' => $plan->requiresReason,
            'is_mergeable' => $plan->isMergeable(),
            'fields' => array_map(
                static fn (MergeFieldPlan $field): array => [
                    'key' => $field->key,
                    'label' => $field->label,
                    'field_type' => $field->fieldType->value,
                    'strategy' => $field->strategy->value,
                    'target_value' => $field->targetValue,
                    'source_value' => $field->sourceValue,
                    'result_value' => $field->resultValue,
                    'origin' => $field->origin->value,
                    'is_conflict' => $field->isConflict,
                    'requires_decision' => $field->requiresDecision,
                    'is_overridden' => $field->isOverridden,
                ],
                $plan->fields,
            ),
            'transfers' => array_map(
                static fn (MergeTransferPlan $transfer): array => [
                    'category' => $transfer->category->value,
                    'policy' => $transfer->policy->value,
                    'count' => $transfer->count,
                ],
                $plan->transfers,
            ),
            'blockers' => array_map(
                static fn (MergeBlocker $blocker): array => [
                    'reason' => $blocker->reason->value,
                    'detail' => $blocker->detail,
                ],
                $plan->blockers,
            ),
        ];
    }
}
