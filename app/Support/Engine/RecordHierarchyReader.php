<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use App\Models\RecordLink;

class RecordHierarchyReader
{
    public function parentCandidate(CustomRecord $record, string $parentRecordId): ?CustomRecord
    {
        return CustomRecord::query()
            ->where('tenant_id', $record->tenant_id)
            ->ofType($record->object_type_id)
            ->whereKey($parentRecordId)
            ->first();
    }

    /**
     * @return list<string>
     */
    public function parentIdsOf(string $tenantId, string $carrierId, string $recordId): array
    {
        $ids = RecordLink::query()
            ->where('tenant_id', $tenantId)
            ->where('relationship_type_id', $carrierId)
            ->where('to_record_id', $recordId)
            ->pluck('from_record_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        sort($ids);

        return $ids;
    }
}
