<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\DTOs\Engine\RecordTreeNode;
use App\DTOs\Engine\RecordTreeResult;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Support\Engine\AncestorChainNotifier;
use App\Support\Engine\RecordHierarchyReader;
use App\Support\Engine\RecordTreeQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReparentRecordAction
{
    public function __construct(
        private readonly LinkRecordsAction $linkRecordsAction,
        private readonly RecordTreeQuery $recordTreeQuery,
        private readonly AncestorChainNotifier $ancestorChainNotifier,
        private readonly RecordHierarchyReader $hierarchyReader,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(CustomRecord $record, array $input): void
    {
        $validated = Validator::make($input, [
            'parent_record_id' => ['nullable', 'string', 'ulid'],
        ])->validate();

        $submitted = $validated['parent_record_id'] ?? null;
        $newParentRecordId = is_string($submitted) ? $submitted : null;

        $this->guardHierarchyEnabled($record);
        $this->guardRecordIsLive($record);

        $tenantId = $record->tenant_id;
        $carrierId = (string) $record->objectType->hierarchy_relationship_type_id;
        $recordId = (string) $record->getKey();

        $newParent = $newParentRecordId === null
            ? null
            : $this->resolveNewParent($record, $newParentRecordId);

        $currentParentIds = $this->hierarchyReader->parentIdsOf($tenantId, $carrierId, $recordId);
        $targetParentIds = $newParentRecordId === null ? [] : [$newParentRecordId];

        if ($currentParentIds === $targetParentIds) {
            return;
        }

        $this->guardNotSelfParent($recordId, $newParentRecordId);
        $this->guardAcyclic($tenantId, $carrierId, $recordId, $newParentRecordId);

        $oldChain = $this->recordTreeQuery->ancestorsOf($tenantId, $carrierId, $recordId);

        if ($oldChain->isTruncated) {
            $this->refuseIncompleteTraversal($tenantId, $recordId, $newParentRecordId, 'old_ancestors', $oldChain);
        }

        $newChain = $this->newChainNodes($tenantId, $carrierId, $recordId, $newParent);

        DB::transaction(function () use ($tenantId, $carrierId, $recordId, $newParent, $oldChain, $newChain): void {
            RecordLink::query()
                ->where('tenant_id', $tenantId)
                ->where('relationship_type_id', $carrierId)
                ->where('to_record_id', $recordId)
                ->delete();

            if ($newParent instanceof CustomRecord) {
                $this->linkRecordsAction->execute([
                    'relationship_type_id' => $carrierId,
                    'from_record_id' => (string) $newParent->getKey(),
                    'to_record_id' => $recordId,
                    'position' => 0,
                ]);
            }

            $this->ancestorChainNotifier->notify($tenantId, $oldChain->nodes);
            $this->ancestorChainNotifier->notify($tenantId, $newChain);
        });
    }

    /**
     * @throws ValidationException
     */
    private function guardHierarchyEnabled(CustomRecord $record): void
    {
        if (!$record->objectType->hasHierarchy()) {
            throw ValidationException::withMessages([
                'parent_record_id' => __('i18n.backend.actions.engine.reparent_record_action.no_hierarchy_is_enabled_for_this_object_type'),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardRecordIsLive(CustomRecord $record): void
    {
        if ($record->trashed()) {
            throw ValidationException::withMessages([
                'record' => __('i18n.backend.actions.engine.reparent_record_action.a_deleted_record_cannot_be_moved_in_the_hierarchy'),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardNotSelfParent(string $recordId, ?string $newParentRecordId): void
    {
        if ($newParentRecordId === $recordId) {
            throw ValidationException::withMessages([
                'parent_record_id' => __('i18n.backend.actions.engine.reparent_record_action.a_record_cannot_be_its_own_parent'),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardAcyclic(string $tenantId, string $carrierId, string $recordId, ?string $newParentRecordId): void
    {
        if ($newParentRecordId === null) {
            return;
        }

        $descendants = $this->recordTreeQuery->descendantsOf($tenantId, $carrierId, $recordId);

        if ($descendants->isTruncated || $descendants->isCycleDetected) {
            $this->refuseIncompleteTraversal($tenantId, $recordId, $newParentRecordId, 'descendants', $descendants);
        }

        foreach ($descendants->nodes as $descendant) {
            if ($descendant->recordId === $newParentRecordId) {
                throw ValidationException::withMessages([
                    'parent_record_id' => __('i18n.backend.actions.engine.reparent_record_action.the_selected_parent_record_is_a_descendant_of_this'),
                ]);
            }
        }
    }

    /**
     * @return list<RecordTreeNode>
     *
     * @throws ValidationException
     */
    private function newChainNodes(string $tenantId, string $carrierId, string $recordId, ?CustomRecord $newParent): array
    {
        if (!$newParent instanceof CustomRecord) {
            return [];
        }

        $newParentId = (string) $newParent->getKey();
        $chain = $this->recordTreeQuery->ancestorsOf($tenantId, $carrierId, $newParentId);

        if ($chain->isTruncated) {
            $this->refuseIncompleteTraversal($tenantId, $recordId, $newParentId, 'new_ancestors', $chain);
        }

        return [
            new RecordTreeNode(
                recordId: $newParentId,
                objectTypeId: $newParent->object_type_id,
                depth: 1,
            ),
            ...$chain->nodes,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function refuseIncompleteTraversal(
        string $tenantId,
        string $recordId,
        ?string $newParentRecordId,
        string $traversal,
        RecordTreeResult $result,
    ): never {
        Log::warning('Refused a record reparent because the hierarchy traversal was incomplete.', [
            'tenant_id' => $tenantId,
            'record_id' => $recordId,
            'new_parent_record_id' => $newParentRecordId,
            'traversal' => $traversal,
            'is_truncated' => $result->isTruncated,
            'is_cycle_detected' => $result->isCycleDetected,
        ]);

        throw ValidationException::withMessages([
            'parent_record_id' => __('i18n.backend.actions.engine.reparent_record_action.the_hierarchy_could_not_be_checked_completely_the_move'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function resolveNewParent(CustomRecord $record, string $newParentRecordId): CustomRecord
    {
        $parent = $this->hierarchyReader->parentCandidate($record, $newParentRecordId);

        if (!$parent instanceof CustomRecord) {
            throw ValidationException::withMessages([
                'parent_record_id' => __('i18n.backend.actions.engine.reparent_record_action.the_selected_parent_record_is_not_available'),
            ]);
        }

        return $parent;
    }
}
