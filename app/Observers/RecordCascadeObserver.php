<?php

declare(strict_types=1);

namespace App\Observers;

use App\DTOs\Engine\RecordTreeNode;
use App\Enums\Engine\CascadeBehavior;
use App\Models\CustomRecord;
use App\Models\RecordLink;
use App\Support\Engine\AncestorChainNotifier;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\RecordEndpointResolver;
use App\Support\Engine\RecordTreeQuery;
use App\Support\Engine\RollupOwnerStarter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecordCascadeObserver
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly RecordTreeQuery $treeQuery,
        private readonly AncestorChainNotifier $chainNotifier,
        private readonly RollupOwnerStarter $rollupStarter,
        private readonly RecordEndpointResolver $endpoints,
    ) {}

    /**
     * @throws Throwable
     */
    public function deleting(CustomRecord $record): void
    {
        if ($record->isForceDeleting()) {
            return;
        }

        $this->run($record);
    }

    public function deleted(CustomRecord $record): void
    {
        if ($record->isForceDeleting()) {
            return;
        }

        $ancestors = $record->ancestorChainBeforeDelete;
        $record->ancestorChainBeforeDelete = [];

        $this->chainNotifier->notify((string) $record->getAttribute('tenant_id'), $ancestors);
    }

    /**
     * @throws Throwable
     */
    public function run(CustomRecord $record): void
    {
        /** @var list<string> $visited */
        $visited = [];
        /** @var list<string> $recordsToSoftDelete */
        $recordsToSoftDelete = [];
        /** @var list<string> $linksToDelete */
        $linksToDelete = [];

        $this->resolve($record, $visited, $recordsToSoftDelete, $linksToDelete);

        $record->ancestorChainBeforeDelete = $this->ancestorChainOf($record);

        $tenantId = (string) $record->getAttribute('tenant_id');
        $this->rollupStarter->startForRecordIds(
            $tenantId,
            $this->parentsOutsideDeletion($tenantId, $record, $recordsToSoftDelete),
        );

        DB::transaction(function () use ($record, $recordsToSoftDelete, $linksToDelete): void {
            $deletedAt = now()->toIso8601String();

            if ($linksToDelete !== []) {
                RecordLink::query()->whereKey($linksToDelete)->delete();
            }

            $this->auditRecorder->record(
                $record,
                ['deleted_at' => null],
                ['deleted_at' => $deletedAt],
                $record->version,
            );

            foreach ($recordsToSoftDelete as $childId) {
                $child = CustomRecord::query()->whereKey($childId)->first();

                if ($child !== null && $child->getAttribute('deleted_at') === null) {
                    $child->deleteQuietly();

                    $this->auditRecorder->record(
                        $child,
                        ['deleted_at' => null],
                        ['deleted_at' => $deletedAt],
                        $child->version,
                    );
                }
            }
        });
    }

    /**
     * @param  list<string>  $recordsToSoftDelete
     * @return list<string>
     */
    private function parentsOutsideDeletion(string $tenantId, CustomRecord $record, array $recordsToSoftDelete): array
    {
        if ($recordsToSoftDelete === []) {
            return [];
        }

        $deleted = [...$recordsToSoftDelete, (string) $record->getKey()];

        /** @var list<string> $parentIds */
        $parentIds = RecordLink::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('to_record_id', $recordsToSoftDelete)
            ->whereNotIn('from_record_id', $deleted)
            ->distinct()
            ->pluck('from_record_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return $parentIds;
    }

    /**
     * @return list<RecordTreeNode>
     */
    private function ancestorChainOf(CustomRecord $record): array
    {
        $objectType = $record->objectType()->withTrashed()->first();

        if ($objectType === null || !$objectType->hasHierarchy()) {
            return [];
        }

        $tenantId = (string) $record->getAttribute('tenant_id');
        $relationshipTypeId = (string) $objectType->hierarchy_relationship_type_id;
        $recordId = (string) $record->getKey();

        $result = $this->treeQuery->ancestorsOf($tenantId, $relationshipTypeId, $recordId);

        if ($result->isTruncated) {
            Log::warning('Notified a shortened ancestor chain after a record deletion; ancestors beyond the traversal ceiling keep a stale roll-up value.', [
                'tenant_id' => $tenantId,
                'record_id' => $recordId,
                'relationship_type_id' => $relationshipTypeId,
                'node_count' => count($result->nodes),
                'is_truncated' => true,
            ]);
        }

        return $result->nodes;
    }

    /**
     * @param  list<string>  $visited
     * @param  list<string>  $recordsToSoftDelete
     * @param  list<string>  $linksToDelete
     */
    private function resolve(CustomRecord $record, array &$visited, array &$recordsToSoftDelete, array &$linksToDelete): void
    {
        $id = (string) $record->getKey();

        if (in_array($id, $visited, true)) {
            return;
        }

        $visited[] = $id;

        $links = RecordLink::query()
            ->where('from_record_id', $id)
            ->where('tenant_id', $record->getAttribute('tenant_id'))
            ->with('relationshipType')
            ->get();

        foreach ($links as $link) {
            $child = CustomRecord::query()->whereKey($link->to_record_id)->first();

            switch ($link->relationshipType->cascade_behavior) {
                case CascadeBehavior::Restrict:
                    $this->guardRestrict($child ?? $this->endpoints->find(
                        (string) $link->to_record_type,
                        $link->to_record_id,
                    ));

                    break;

                case CascadeBehavior::Nullify:
                    $linksToDelete[] = (string) $link->getKey();

                    break;

                case CascadeBehavior::Cascade:
                    if ($child !== null && $child->getAttribute('deleted_at') === null) {
                        $recordsToSoftDelete[] = (string) $child->getKey();
                        $this->resolve($child, $visited, $recordsToSoftDelete, $linksToDelete);
                    }

                    break;
            }
        }
    }

    private function guardRestrict(?Model $child): void
    {
        if ($child !== null && $child->getAttribute('deleted_at') === null) {
            throw ValidationException::withMessages([
                'relations' => __('i18n.backend.observers.record_cascade_observer.the_record_cannot_be_deleted_while_a_locked_relationship'),
            ]);
        }
    }
}
