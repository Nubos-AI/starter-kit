<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\Models\ImportJob;
use App\Models\User;
use App\Notifications\ImportCompletedNotification;

class NotifyImportCompletedAction
{
    public function execute(string $importJobId): void
    {
        $importJob = ImportJob::query()->whereKey($importJobId)->first();

        if (!$importJob instanceof ImportJob) {
            return;
        }

        $user = User::query()->active()->whereKey($importJob->user_id)->first();

        $user?->notify(new ImportCompletedNotification($importJob));
    }
}
