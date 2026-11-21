<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\CustomRecord;
use App\Support\Reminders\ReminderRecordSource;

class StaticReminderRecordSource extends ReminderRecordSource
{
    /**
     * @var list<string>
     */
    public array $askedFor = [];

    /**
     * @param  array<string, CustomRecord>  $records
     */
    public function __construct(private readonly array $records = []) {}

    public function find(string $recordId): ?CustomRecord
    {
        $this->askedFor[] = $recordId;

        return $this->records[$recordId] ?? null;
    }
}
