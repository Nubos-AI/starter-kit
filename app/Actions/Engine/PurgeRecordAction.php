<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\CustomRecord;
use App\Support\Modules\RecordExtensions;
use Illuminate\Support\Facades\DB;
use Throwable;

class PurgeRecordAction
{
    public function __construct(private readonly RecordExtensions $extensions) {}

    /**
     * @throws Throwable
     */
    public function execute(CustomRecord $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->extensions->deleting($record);
            $record->forceDelete();
        });
    }
}
