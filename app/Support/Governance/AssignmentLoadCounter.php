<?php

declare(strict_types=1);

namespace App\Support\Governance;

use App\Models\CustomRecord;
use App\Models\ReminderTask;
use App\Scopes\TeamRecordAccessScope;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;

class AssignmentLoadCounter
{
    /**
     * @param  list<string>  $userIds
     * @return array<string, int>
     */
    public function countFor(array $userIds, string $tenantId): array
    {
        if ($userIds === []) {
            return [];
        }

        $records = $this->openRecordCounts($userIds, $tenantId);
        $tasks = $this->openTaskCounts($userIds, $tenantId);

        $counts = [];

        foreach ($userIds as $userId) {
            $counts[$userId] = ($records[$userId] ?? 0) + ($tasks[$userId] ?? 0);
        }

        return $counts;
    }

    /**
     * @param  list<string>  $userIds
     * @return array<string, int>
     */
    private function openRecordCounts(array $userIds, string $tenantId): array
    {
        return CustomRecord::query()
            ->withoutGlobalScopes([TenantScope::class, TeamRecordAccessScope::class])
            ->where('custom_records.tenant_id', $tenantId)
            ->whereIn('custom_records.owner_id', $userIds)
            ->tap($this->constrainOpenRecords(...))
            ->groupBy('custom_records.owner_id')
            ->select('custom_records.owner_id')
            ->selectRaw('COUNT(*) as aggregate')
            ->pluck('aggregate', 'custom_records.owner_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** @param Builder<CustomRecord> $records */
    protected function constrainOpenRecords(Builder $records): void {}

    /**
     * @param  list<string>  $userIds
     * @return array<string, int>
     */
    private function openTaskCounts(array $userIds, string $tenantId): array
    {
        return ReminderTask::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('tenant_id', $tenantId)
            ->whereIn('assignee_id', $userIds)
            ->whereNull('done_at')
            ->groupBy('assignee_id')
            ->select('assignee_id')
            ->selectRaw('COUNT(*) as aggregate')
            ->pluck('aggregate', 'assignee_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }
}
