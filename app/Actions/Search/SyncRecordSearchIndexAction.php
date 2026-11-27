<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\DTOs\Engine\RecordChangeBatch;
use App\Models\CustomRecord;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Database\Eloquent\Collection;

class SyncRecordSearchIndexAction
{
    public function __construct(private readonly TenantBinder $tenantBinder) {}

    public function execute(RecordChangeBatch $changes): int
    {
        $recordIdsByTenant = [];

        foreach ($changes->changes as $change) {
            $recordIdsByTenant[$change->tenantId][$change->recordId] = $change->recordId;
        }

        foreach ($recordIdsByTenant as $tenantId => $recordIds) {
            $this->tenantBinder->run((string) $tenantId, fn () => $this->syncTenantRecords((string) $tenantId, array_values($recordIds)));
        }

        return $changes->count();
    }

    /**
     * @param  list<string>  $recordIds
     */
    private function syncTenantRecords(string $tenantId, array $recordIds): void
    {
        $records = CustomRecord::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('tenant_id', $tenantId)
            ->whereKey($recordIds)
            ->get();

        $searchable = $records->filter(static fn (CustomRecord $record): bool => $record->shouldBeSearchable())->values();
        $removed = $records->reject(static fn (CustomRecord $record): bool => $record->shouldBeSearchable())->values();

        foreach (array_diff($recordIds, array_map('strval', $records->modelKeys())) as $purgedId) {
            $purged = new CustomRecord;
            $purged->setRawAttributes(['id' => $purgedId, 'tenant_id' => $tenantId]);
            $removed->push($purged);
        }

        $this->makeSearchable($searchable);
        $this->removeFromSearch($removed);
    }

    /**
     * @param  Collection<int, CustomRecord>  $records
     */
    private function makeSearchable(Collection $records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        (new CustomRecord)->syncMakeSearchable($records);
    }

    /**
     * @param  Collection<int, CustomRecord>  $records
     */
    private function removeFromSearch(Collection $records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        (new CustomRecord)->syncRemoveFromSearch($records);
    }
}
