<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use App\Models\RecordLink;
use Illuminate\Database\Eloquent\Collection;

class RollupOwnerStarter
{
    private int $batchSize = 500;

    public function __construct(
        private readonly RollupDebounceStarter $starter,
        private readonly RollupOwnerFilter $ownerFilter,
    ) {}

    public function startForLinkOwner(?CustomRecord $owner, string $relationshipTypeId): void
    {
        if ($owner instanceof CustomRecord) {
            $this->startOwn($owner, $relationshipTypeId);
        }
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

        CustomRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($ids)
            ->orderBy('id')
            ->chunk($this->batchSize, function (Collection $records): void {
                foreach ($records as $record) {
                    $this->startOwn($record);
                }
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

    private function startOwn(CustomRecord $record, ?string $relationshipTypeId = null): void
    {
        $tenantId = (string) $record->getAttribute('tenant_id');
        $objectTypeId = (string) $record->getAttribute('object_type_id');

        if (!$this->ownerFilter->ownsRollups($tenantId, $objectTypeId, $relationshipTypeId)) {
            return;
        }

        $this->starter->startOwn($tenantId, $objectTypeId, (string) $record->getKey());
    }
}
