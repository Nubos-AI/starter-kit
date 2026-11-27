<?php

declare(strict_types=1);

namespace App\Actions\Export;

use App\Models\ExportJob;
use App\Models\User;
use App\Notifications\ExportCompletedNotification;

class NotifyExportCompletedAction
{
    public function execute(string $userId, string $exportJobId): void
    {
        $exportJob = ExportJob::query()->whereKey($exportJobId)->first();

        if (!$exportJob instanceof ExportJob) {
            return;
        }

        $user = User::query()->active()->whereKey($userId)->first();

        $user?->notify(new ExportCompletedNotification($exportJob));
    }
}
