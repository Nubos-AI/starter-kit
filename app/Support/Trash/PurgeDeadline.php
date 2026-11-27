<?php

declare(strict_types=1);

namespace App\Support\Trash;

use App\Models\CustomRecord;
use App\Models\ObjectType;
use Carbon\CarbonInterface;

class PurgeDeadline
{
    public function for(CustomRecord $record, ?ObjectType $objectType = null): ?CarbonInterface
    {
        $deletedAt = $record->deleted_at;
        $retentionDays = ($objectType ?? $record->objectType)->retention_days;

        if ($deletedAt === null || $retentionDays === null) {
            return null;
        }

        return $deletedAt->copy()
            ->setTimezone($this->timezone())
            ->addDays($retentionDays)
            ->endOfDay();
    }

    public function timezone(): string
    {
        return (string) config('engine.trash.purge_timezone');
    }
}
