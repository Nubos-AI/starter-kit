<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RecordTreeNode;
use App\DTOs\Engine\RecordTreeResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecordTreeQuery
{
    public function ancestorsOf(string $tenantId, string $relationshipTypeId, string $recordId): RecordTreeResult
    {
        return $this->traverse($this->ancestorSql(), $tenantId, $relationshipTypeId, $recordId);
    }

    public function descendantsOf(string $tenantId, string $relationshipTypeId, string $recordId): RecordTreeResult
    {
        return $this->traverse($this->descendantSql(), $tenantId, $relationshipTypeId, $recordId);
    }

    public function childrenOf(string $tenantId, string $relationshipTypeId, string $recordId): RecordTreeResult
    {
        $maxResultRows = (int) config('engine.hierarchy.max_result_rows');

        /** @var list<object{record_id: string, object_type_id: string}> $rows */
        $rows = DB::select($this->childrenSql(), [
            $recordId,
            $tenantId,
            $relationshipTypeId,
            $tenantId,
            $maxResultRows,
        ]);

        /** @var list<RecordTreeNode> $nodes */
        $nodes = [];

        foreach ($rows as $row) {
            $nodes[] = new RecordTreeNode(
                recordId: $row->record_id,
                objectTypeId: $row->object_type_id,
                depth: 1,
            );
        }

        $isRowCeilingReached = count($rows) >= $maxResultRows;

        if ($isRowCeilingReached) {
            $this->warnCeilingReached($tenantId, $relationshipTypeId, $recordId, false, true, count($rows));
        }

        return new RecordTreeResult($nodes, $isRowCeilingReached, false);
    }

    private function traverse(string $sql, string $tenantId, string $relationshipTypeId, string $recordId): RecordTreeResult
    {
        $maxTraversalDepth = (int) config('engine.hierarchy.max_traversal_depth');
        $maxResultRows = (int) config('engine.hierarchy.max_result_rows');

        /** @var list<object{record_id: string, object_type_id: string, depth: int, is_cycle: bool}> $rows */
        $rows = DB::select($sql, [
            $recordId,
            $tenantId,
            $tenantId,
            $relationshipTypeId,
            $tenantId,
            $maxTraversalDepth + 1,
            $maxResultRows,
        ]);

        /** @var list<RecordTreeNode> $nodes */
        $nodes = [];
        $isDepthCeilingReached = false;
        $isRowCeilingReached = count($rows) >= $maxResultRows;
        $isCycleDetected = false;

        foreach ($rows as $row) {
            if ($row->is_cycle) {
                $isCycleDetected = true;
            }

            if ($row->depth > $maxTraversalDepth) {
                $isDepthCeilingReached = true;

                continue;
            }

            $nodes[] = new RecordTreeNode(
                recordId: $row->record_id,
                objectTypeId: $row->object_type_id,
                depth: $row->depth,
                isCycleDetected: $row->is_cycle,
            );
        }

        if ($isDepthCeilingReached || $isRowCeilingReached) {
            $this->warnCeilingReached(
                $tenantId,
                $relationshipTypeId,
                $recordId,
                $isDepthCeilingReached,
                $isRowCeilingReached,
                count($rows),
            );
        }

        return new RecordTreeResult($nodes, $isDepthCeilingReached || $isRowCeilingReached, $isCycleDetected);
    }

    private function warnCeilingReached(
        string $tenantId,
        string $relationshipTypeId,
        string $recordId,
        bool $isDepthCeilingReached,
        bool $isRowCeilingReached,
        int $rowCount,
    ): void {
        Log::warning('Truncated a record tree traversal because a safety ceiling was reached.', [
            'tenant_id' => $tenantId,
            'relationship_type_id' => $relationshipTypeId,
            'record_id' => $recordId,
            'is_depth_ceiling_reached' => $isDepthCeilingReached,
            'is_row_ceiling_reached' => $isRowCeilingReached,
            'max_traversal_depth' => (int) config('engine.hierarchy.max_traversal_depth'),
            'max_result_rows' => (int) config('engine.hierarchy.max_result_rows'),
            'row_count' => $rowCount,
        ]);
    }

    private function edgePredicate(): string
    {
        return 'rl.tenant_id = ? and rl.relationship_type_id = ? and cr.tenant_id = ? and cr.deleted_at is null';
    }

    private function ancestorSql(): string
    {
        $edge = $this->edgePredicate();

        return <<<SQL
            with recursive record_tree as (
                select anchor.id as record_id, anchor.object_type_id as object_type_id, 0 as depth
                from custom_records anchor
                where anchor.id = ? and anchor.tenant_id = ? and anchor.deleted_at is null
                union all
                select cr.id, cr.object_type_id, walked.depth + 1
                from record_tree walked
                join record_links rl on rl.to_record_id = walked.record_id
                join custom_records cr on cr.id = rl.from_record_id
                where {$edge} and walked.depth < ?
            ) cycle record_id set is_cycle using cycle_path
            select record_id, object_type_id, depth, is_cycle
            from (
                select record_id, object_type_id, depth, is_cycle
                from record_tree
                where depth > 0
                limit ?
            ) bounded
            order by depth, record_id
            SQL;
    }

    private function descendantSql(): string
    {
        $edge = $this->edgePredicate();

        return <<<SQL
            with recursive record_tree as (
                select anchor.id as record_id, anchor.object_type_id as object_type_id, 0 as depth
                from custom_records anchor
                where anchor.id = ? and anchor.tenant_id = ? and anchor.deleted_at is null
                union all
                select cr.id, cr.object_type_id, walked.depth + 1
                from record_tree walked
                join record_links rl on rl.from_record_id = walked.record_id
                join custom_records cr on cr.id = rl.to_record_id
                where {$edge} and walked.depth < ?
            ) cycle record_id set is_cycle using cycle_path
            select record_id, object_type_id, depth, is_cycle
            from (
                select record_id, object_type_id, depth, is_cycle
                from record_tree
                where depth > 0
                limit ?
            ) bounded
            order by depth, record_id
            SQL;
    }

    private function childrenSql(): string
    {
        $edge = $this->edgePredicate();

        return <<<SQL
            select cr.id as record_id, cr.object_type_id as object_type_id
            from record_links rl
            join custom_records cr on cr.id = rl.to_record_id
            where rl.from_record_id = ? and {$edge}
            order by cr.id
            limit ?
            SQL;
    }
}
