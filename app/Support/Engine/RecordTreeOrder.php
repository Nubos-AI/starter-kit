<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RecordTreeOrderResult;
use App\Enums\Engine\RecordTreeOrderReason;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RecordTreeOrder
{
    public static string $depthAttribute = 'hierarchy_depth';

    /**
     * @param  Builder<CustomRecord>  $query
     */
    public function apply(Builder $query, ObjectType $objectType, string $tenantId): RecordTreeOrderResult
    {
        if (!$objectType->hasHierarchy()) {
            return RecordTreeOrderResult::skipped(RecordTreeOrderReason::NoHierarchy);
        }

        $maxRows = $this->maxRows();

        if ($this->exceedsCeiling($tenantId, $objectType, $maxRows)) {
            return RecordTreeOrderResult::skipped(RecordTreeOrderReason::TooManyRows);
        }

        $carrierId = (string) $objectType->hierarchy_relationship_type_id;

        if ($query->getQuery()->columns === null) {
            $query->select('custom_records.*');
        }

        $query
            ->leftJoin(
                DB::raw('('.$this->treeSql().') as record_tree'),
                'record_tree.id',
                '=',
                'custom_records.id',
            )
            ->addSelect(DB::raw('record_tree.depth as hierarchy_depth'))
            ->orderByRaw('record_tree.path asc nulls last')
            ->orderBy('custom_records.id');

        $query->getQuery()->addBinding(
            [
                $tenantId,
                $objectType->getKey(),
                $tenantId,
                $carrierId,
                $tenantId,
                $carrierId,
                $tenantId,
                $this->maxDepth(),
                $maxRows,
            ],
            'join',
        );

        return RecordTreeOrderResult::ordered();
    }

    private function exceedsCeiling(string $tenantId, ObjectType $objectType, int $maxRows): bool
    {
        $probe = CustomRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->ofType($objectType)
            ->whereNull('deleted_at')
            ->select('id')
            ->limit($maxRows + 1);

        return DB::query()->fromSub($probe, 'tree_order_probe')->count() > $maxRows;
    }

    private function maxRows(): int
    {
        return (int) config('engine.hierarchy.max_tree_sort_rows');
    }

    private function maxDepth(): int
    {
        return (int) config('engine.hierarchy.max_traversal_depth');
    }

    /**
     * @return literal-string
     */
    private function treeSql(): string
    {
        return <<<'SQL'
            WITH RECURSIVE tree AS (
                SELECT roots.id,
                       ARRAY[roots.created_at::text || '|' || roots.id] AS path,
                       0 AS depth
                FROM custom_records roots
                WHERE roots.tenant_id = ?
                  AND roots.object_type_id = ?
                  AND roots.deleted_at IS NULL
                  AND NOT EXISTS (
                      SELECT 1
                      FROM record_links parent_link
                      JOIN custom_records parents
                        ON parents.id = parent_link.from_record_id
                       AND parents.deleted_at IS NULL
                      WHERE parent_link.tenant_id = ?
                        AND parent_link.relationship_type_id = ?
                        AND parent_link.to_record_id = roots.id
                  )
                UNION ALL
                SELECT children.id,
                       tree.path || (children.created_at::text || '|' || children.id),
                       tree.depth + 1
                FROM custom_records children
                JOIN record_links child_link
                  ON child_link.to_record_id = children.id
                 AND child_link.tenant_id = ?
                 AND child_link.relationship_type_id = ?
                JOIN tree ON tree.id = child_link.from_record_id
                WHERE children.tenant_id = ?
                  AND children.deleted_at IS NULL
                  AND tree.depth < ?
            ) CYCLE id SET is_cycle USING cycle_path
            SELECT tree.id, tree.path, tree.depth
            FROM tree
            WHERE NOT tree.is_cycle
            LIMIT ?
            SQL;
    }
}
