<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Backfill\BackfillBatchResult;
use App\DTOs\Engine\RecordBackfillData;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'RecordBackfill.')]
interface BackfillRecordBatchActivityInterface
{
    #[ActivityMethod(name: 'backfillBatch')]
    public function backfillBatch(RecordBackfillData $input): BackfillBatchResult;
}
