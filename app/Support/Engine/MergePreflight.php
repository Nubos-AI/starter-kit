<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\MergeBlocker;
use App\DTOs\Engine\MergeRequestData;
use App\DTOs\Engine\MergeRuleDecision;
use App\Enums\Engine\MergeBlockReason;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Model;

class MergePreflight
{
    /**
     * @var list<string>
     */
    private array $activeRunStatuses = ['pending', 'running'];

    public function __construct(
        private readonly ObjectTypeRegistry $objectTypes,
        private readonly RecordTreeQuery $recordTreeQuery,
    ) {}

    /**
     * @return list<MergeBlocker>
     */
    public function check(
        CustomRecord $target,
        CustomRecord $source,
        MergeRuleDecision $decision,
        MergeRequestData $request,
    ): array {
        $blockers = [];

        if ($target->getKey() === $source->getKey()) {
            $blockers[] = new MergeBlocker(MergeBlockReason::SameRecord);

            return $blockers;
        }

        if ($target->object_type_id !== $source->object_type_id) {
            $blockers[] = new MergeBlocker(MergeBlockReason::DifferentObjectType);
        }

        if ($target->tenant_id !== $source->tenant_id) {
            $blockers[] = new MergeBlocker(MergeBlockReason::DifferentTenant);
        }

        if ($target->trashed() || $source->trashed()) {
            $blockers[] = new MergeBlocker(MergeBlockReason::Trashed);
        }

        if ($target->merged_into_record_id !== null || $source->merged_into_record_id !== null) {
            $blockers[] = new MergeBlocker(MergeBlockReason::AlreadyMerged);
        }

        if ($blockers !== []) {
            return $blockers;
        }

        $objectType = $this->objectTypes->forRecord($target);

        if ($objectType->is_system) {
            $blockers[] = new MergeBlocker(MergeBlockReason::SystemObjectType);
        }

        if ($decision->forbids()) {
            $blockers[] = new MergeBlocker(MergeBlockReason::RuleForbids, $decision->denyReason);
        }

        return [
            ...$blockers,
            ...$this->versionBlockers($target, $source, $request),
            ...$this->hierarchyBlockers($objectType, $target, $source),
            ...$this->ruleOptionBlockers($objectType, $target, $source, $decision, $request),
        ];
    }

    /**
     * @return list<MergeBlocker>
     */
    private function versionBlockers(CustomRecord $target, CustomRecord $source, MergeRequestData $request): array
    {
        $stale = ($request->targetVersion !== null && $request->targetVersion !== $target->version)
            || ($request->sourceVersion !== null && $request->sourceVersion !== $source->version);

        return $stale ? [new MergeBlocker(MergeBlockReason::StaleVersion)] : [];
    }

    /**
     * @return list<MergeBlocker>
     */
    private function hierarchyBlockers(ObjectType $objectType, CustomRecord $target, CustomRecord $source): array
    {
        $carrierId = $objectType->hierarchy_relationship_type_id;

        if (!is_string($carrierId)) {
            return [];
        }

        $tenantId = $target->tenant_id;
        $sourceId = (string) $source->getKey();
        $targetId = (string) $target->getKey();

        $ancestors = $this->recordTreeQuery->ancestorsOf($tenantId, $carrierId, $targetId);
        $descendants = $this->recordTreeQuery->descendantsOf($tenantId, $carrierId, $targetId);

        foreach ([...$ancestors->nodes, ...$descendants->nodes] as $node) {
            if ($node->recordId === $sourceId) {
                return [new MergeBlocker(MergeBlockReason::HierarchyCycle)];
            }
        }

        return [];
    }

    /**
     * @return list<MergeBlocker>
     */
    protected function ruleOptionBlockers(
        ObjectType $objectType,
        CustomRecord $target,
        CustomRecord $source,
        MergeRuleDecision $decision,
        MergeRequestData $request,
    ): array {
        $blockers = [];

        if ($decision->requiresReason() && !$request->hasReason()) {
            $blockers[] = new MergeBlocker(MergeBlockReason::ReasonRequired);
        }

        if ($decision->requiresDedupMatch() && !$this->sharesDedupKey($objectType, $target, $source)) {
            $blockers[] = new MergeBlocker(MergeBlockReason::DedupMismatch);
        }

        if ($decision->blocksOnRunningAutomations() && $this->hasRunningAutomation($target, $source)) {
            $blockers[] = new MergeBlocker(MergeBlockReason::RunningAutomation);
        }

        return $blockers;
    }

    private function sharesDedupKey(ObjectType $objectType, CustomRecord $target, CustomRecord $source): bool
    {
        $duplicates = $objectType->findDuplicates($source->data ?? [], $source->tenant_id);

        return $duplicates->contains(fn (CustomRecord $candidate): bool => $candidate->getKey() === $target->getKey());
    }

    private function hasRunningAutomation(CustomRecord $target, CustomRecord $source): bool
    {
        /** @var class-string<Model>|null $model */
        $model = config('modules.records.running_process_model');

        if ($model === null) {
            return false;
        }

        return $model::query()
            ->withoutGlobalScopes()
            ->whereIn('record_id', [$target->getKey(), $source->getKey()])
            ->whereIn('status', $this->activeRunStatuses)
            ->exists();
    }
}
