<?php

declare(strict_types=1);

namespace App\Activities\Trash;

use App\Actions\Engine\PurgeRecordAction;
use App\Contracts\Trash\PurgeTrashedRecordsActivityInterface;
use App\Models\CustomRecord;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Trash\PurgeDeadline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class PurgeTrashedRecordsActivity implements PurgeTrashedRecordsActivityInterface
{
    public function __construct(
        private readonly PurgeRecordAction $purgeRecord,
        private readonly PurgeDeadline $purgeDeadline,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
    ) {}

    /**
     * @return list<string>
     */
    public function expiredRecordIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->expiredRecordsQuery($this->maintenanceLocks->lockedTenantIds())
            ->pluck('custom_records.id')
            ->all();

        return $ids;
    }

    /**
     * @param  list<string>  $lockedTenantIds
     * @return Builder<CustomRecord>
     */
    public function expiredRecordsQuery(array $lockedTenantIds): Builder
    {
        $timezone = $this->purgeDeadline->timezone();

        return $this->trashedRecords()
            ->select('custom_records.id')
            ->join('object_types', 'object_types.id', '=', 'custom_records.object_type_id')
            ->whereNotNull('object_types.retention_days')
            ->when(
                $lockedTenantIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('custom_records.tenant_id', $lockedTenantIds),
            )
            ->whereRaw(
                "date_trunc('day', (custom_records.deleted_at AT TIME ZONE 'UTC' AT TIME ZONE ?)"
                    .' + make_interval(days => object_types.retention_days))'
                    ." + interval '1 day' - interval '1 microsecond' <= ?",
                [$timezone, CarbonImmutable::now($timezone)->toDateTimeString()],
            )
            ->orderBy('custom_records.deleted_at');
    }

    public function purgeRecord(string $recordId): bool
    {
        $record = $this->trashedRecords()->whereKey($recordId)->first();

        if (!$record instanceof CustomRecord) {
            return false;
        }

        if ($this->maintenanceLocks->activeFor($record->tenant_id) !== null) {
            return false;
        }

        try {
            $this->purgeRecord->execute($record);
        } catch (Throwable $exception) {
            Log::error('Purging a trashed record failed.', [
                'record' => $recordId,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return Builder<CustomRecord>
     */
    private function trashedRecords(): Builder
    {
        return CustomRecord::withoutGlobalScopes()->onlyTrashed();
    }
}
