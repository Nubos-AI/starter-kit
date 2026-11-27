<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Actions\Search\SyncRecordSearchIndexAction;
use App\Contracts\Engine\SyncRecordSearchIndexActivityInterface;
use App\DTOs\Engine\RecordChangeBatch;
use App\Support\Temporal\ProcessStepRunner;

class SyncRecordSearchIndexActivity implements SyncRecordSearchIndexActivityInterface
{
    public function __construct(
        private readonly SyncRecordSearchIndexAction $syncSearchIndex,
        private readonly ProcessStepRunner $steps,
    ) {}

    public function syncSearchIndex(RecordChangeBatch $changes): int
    {
        return $this->steps->run(
            'records.sync-search-index',
            ['change_count' => $changes->count()],
            fn (): int => $this->syncSearchIndex->execute($changes),
        );
    }
}
