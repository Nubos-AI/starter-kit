<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\DTOs\Engine\MergeFieldPlan;
use App\DTOs\Engine\MergePlanData;
use App\DTOs\Engine\MergeRequestData;
use App\DTOs\Engine\RecordTreeNode;
use App\Exceptions\Engine\MergeRefusedException;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordMerge;
use App\Models\User;
use App\Support\Engine\AncestorChainNotifier;
use App\Support\Engine\MergePlanner;
use App\Support\Engine\MergeRuleResolver;
use App\Support\Engine\MergeTransferExecutor;
use App\Support\Engine\RecordTreeQuery;
use App\Support\Timeline\TimelineRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class MergeRecordsAction
{
    public function __construct(
        private readonly MergePlanner $planner,
        private readonly MergeRuleResolver $ruleResolver,
        private readonly MergeTransferExecutor $transfers,
        private readonly UpdateRecordAction $updateRecord,
        private readonly TimelineRecorder $timeline,
        private readonly RecordTreeQuery $recordTreeQuery,
        private readonly AncestorChainNotifier $ancestorChainNotifier,
    ) {}

    /**
     * @throws MergeRefusedException
     * @throws Throwable
     */
    public function execute(MergeRequestData $request): RecordMerge
    {
        $target = $this->locate($request->targetId);
        $source = $this->locate($request->sourceId);

        $plan = $this->planner->plan($target, $source, $request);

        if (!$plan->isMergeable()) {
            throw new MergeRefusedException($plan->blockers);
        }

        $decision = $this->ruleResolver->resolve($target, $source);

        return DB::transaction(function () use ($target, $source, $plan, $decision, $request): RecordMerge {
            $chains = $this->ancestorChains($target, $source);

            $this->updateRecord->execute($target, [
                'data' => $plan->resultingData(),
                'version' => $target->version,
            ]);

            $transfers = $this->transfers->execute($target, $source, $decision);

            $merge = RecordMerge::query()->create([
                'tenant_id' => $target->tenant_id,
                'object_type_id' => $target->object_type_id,
                'target_record_id' => $target->getKey(),
                'source_record_id' => $source->getKey(),
                'merge_rule_id' => $plan->ruleId,
                'actor_id' => $this->actorId(),
                'reason' => $request->hasReason() ? trim((string) $request->reason) : null,
                'resolution' => $this->resolution($plan),
                'transfers' => $transfers,
            ]);

            $source->forceFill([
                'merged_into_record_id' => $target->getKey(),
                'merged_at' => now(),
            ])->save();

            $source->delete();

            $this->recordTimeline($target, $source, $merge);

            foreach ($chains as $chain) {
                $this->ancestorChainNotifier->notify($target->tenant_id, $chain);
            }

            return $merge;
        });
    }

    private function locate(string $recordId): CustomRecord
    {
        return CustomRecord::query()
            ->withTrashed()
            ->whereKey($recordId)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolution(MergePlanData $plan): array
    {
        $fields = [];

        foreach ($plan->fields as $field) {
            $fields[$field->key] = [
                'strategy' => $field->strategy->value,
                'origin' => $field->origin->value,
                'before' => $field->targetValue,
                'after' => $field->resultValue,
                'source_value' => $field->sourceValue,
                'overridden' => $field->isOverridden,
            ];
        }

        return [
            'rule_id' => $plan->ruleId,
            'rule_name' => $plan->ruleName,
            'target_version' => $plan->targetVersion,
            'source_version' => $plan->sourceVersion,
            'fields' => $fields,
            'changed_keys' => array_values(array_map(
                static fn (MergeFieldPlan $field): string => $field->key,
                array_filter(
                    $plan->fields,
                    static fn (MergeFieldPlan $field): bool => $field->targetValue !== $field->resultValue,
                ),
            )),
        ];
    }

    /**
     * @return list<list<RecordTreeNode>>
     */
    private function ancestorChains(CustomRecord $target, CustomRecord $source): array
    {
        $objectType = ObjectType::query()->whereKey($target->object_type_id)->firstOrFail();
        $carrierId = $objectType->hierarchy_relationship_type_id;

        if (!is_string($carrierId)) {
            return [];
        }

        $tenantId = $target->tenant_id;

        return [
            $this->recordTreeQuery->ancestorsOf($tenantId, $carrierId, (string) $target->getKey())->nodes,
            $this->recordTreeQuery->ancestorsOf($tenantId, $carrierId, (string) $source->getKey())->nodes,
        ];
    }

    private function recordTimeline(CustomRecord $target, CustomRecord $source, RecordMerge $merge): void
    {
        $mergeId = (string) $merge->getKey();

        $this->timeline->record($target, 'merge', [[
            'source_id' => $mergeId,
            'payload' => [
                'direction' => 'absorbed',
                'counterpart_id' => (string) $source->getKey(),
                'counterpart_number' => $source->record_number,
            ],
        ]]);

        $this->timeline->record($source, 'merge', [[
            'source_id' => $mergeId,
            'payload' => [
                'direction' => 'merged_into',
                'counterpart_id' => (string) $target->getKey(),
                'counterpart_number' => $target->record_number,
            ],
        ]]);
    }

    private function actorId(): ?string
    {
        $actor = Auth::user();

        return $actor instanceof User ? (string) $actor->getKey() : null;
    }
}
