<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Models\RecordActivity;
use App\Models\TimelineEntry;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeleteRecordActivityAction
{
    /**
     * @throws Throwable
     */
    public function execute(RecordActivity $activity): void
    {
        DB::transaction(function () use ($activity): void {
            TimelineEntry::query()
                ->where('record_id', $activity->record_id)
                ->where('source_key', 'activity')
                ->where('source_id', $activity->id)
                ->delete();

            $activity->delete();
        });
    }
}
