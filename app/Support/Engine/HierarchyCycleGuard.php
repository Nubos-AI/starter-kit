<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RecordTreeResult;
use App\Exceptions\Engine\HierarchyCycleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HierarchyCycleGuard
{
    public function __construct(private readonly RecordTreeQuery $recordTreeQuery) {}

    /**
     * @throws HierarchyCycleException
     */
    public function assertAcyclic(string $tenantId, string $carrierId, string $parentRecordId, string $childRecordId): void
    {
        if ($parentRecordId === $childRecordId) {
            $this->refuse(__('i18n.backend.support.engine.hierarchy_cycle_guard.a_record_cannot_be_its_own_parent'));
        }

        DB::select('select pg_advisory_xact_lock(hashtext(?::text), hashtext(?::text))', [$tenantId, $carrierId]);

        $descendants = $this->recordTreeQuery->descendantsOf($tenantId, $carrierId, $childRecordId);

        if ($descendants->isTruncated || $descendants->isCycleDetected) {
            $this->refuseIncompleteTraversal($tenantId, $childRecordId, $parentRecordId, $descendants);
        }

        foreach ($descendants->nodes as $descendant) {
            if ($descendant->recordId === $parentRecordId) {
                $this->refuse(__('i18n.backend.support.engine.hierarchy_cycle_guard.the_selected_parent_record_is_a_descendant_of_this'));
            }
        }
    }

    /**
     * @throws HierarchyCycleException
     */
    private function refuseIncompleteTraversal(string $tenantId, string $recordId, string $parentRecordId, RecordTreeResult $result): never
    {
        Log::warning('Refused a hierarchy link because the descendant traversal was incomplete.', [
            'tenant_id' => $tenantId,
            'record_id' => $recordId,
            'new_parent_record_id' => $parentRecordId,
            'traversal' => 'descendants',
            'is_truncated' => $result->isTruncated,
            'is_cycle_detected' => $result->isCycleDetected,
        ]);

        $this->refuse(__('i18n.backend.support.engine.hierarchy_cycle_guard.the_hierarchy_could_not_be_checked_completely_the_move'));
    }

    /**
     * @throws HierarchyCycleException
     */
    private function refuse(string $message): never
    {
        throw HierarchyCycleException::withMessages(['to_record_id' => $message]);
    }
}
