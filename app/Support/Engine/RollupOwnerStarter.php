<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use App\Models\RecordLink;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RollupOwnerStarter
{
    private int $batchSize = 500;

    public function __construct(private readonly RollupDebounceStarter $starter) {}

    public function startForRecords(?CustomRecord ...$records): void
    {
        /** @var array<string, CustomRecord> $targets */
        $targets = [];

        foreach ($records as $record) {
            if ($record instanceof CustomRecord) {
                $targets[(string) $record->getKey()] = $record;
            }
        }

        if ($targets === []) {
            return;
        }

        DB::afterCommit(function () use ($targets): void {
            $this->startAll($targets);
        });
    }

    /**
     * @param  array<int, string>  $recordIds
     */
    public function startForRecordIds(string $tenantId, array $recordIds): void
    {
        $ids = array_values(array_unique(array_filter($recordIds, static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return;
        }

        DB::afterCommit(function () use ($tenantId, $ids): void {
            CustomRecord::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($ids)
                ->orderBy('id')
                ->chunk($this->batchSize, function (Collection $records): void {
                    $this->startAll($records->all());
                });
        });
    }

    public function startForParentsOf(CustomRecord $record): void
    {
        $tenantId = (string) $record->getAttribute('tenant_id');

        $parentIds = RecordLink::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('to_record_id', $record->getKey())
            ->distinct()
            ->pluck('from_record_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $this->startForRecordIds($tenantId, array_values($parentIds));
    }

    /**
     * @param  array<array-key, CustomRecord>  $records
     */
    private function startAll(array $records): void
    {
        foreach ($records as $record) {
            $this->starter->startOwn(
                (string) $record->getAttribute('tenant_id'),
                (string) $record->getAttribute('object_type_id'),
                (string) $record->getKey(),
            );
        }
    }
}
