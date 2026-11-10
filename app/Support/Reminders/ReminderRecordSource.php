<?php

declare(strict_types=1);

namespace App\Support\Reminders;

use App\Models\CustomRecord;

class ReminderRecordSource
{
    public function find(string $recordId): ?CustomRecord
    {
        return CustomRecord::query()
            ->with('objectType')
            ->whereKey($recordId)
            ->first();
    }
}
