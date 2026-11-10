<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Enums\Export\ExportJobStatus;
use App\Models\ExportJob;
use App\Models\User;
use App\Notifications\ExportCompletedNotification;
use App\Support\Tenancy\TenantBinder;

class ExportFinalizer
{
    public function __construct(private readonly TenantBinder $tenantBinder) {}

    public function finalize(string $tenantId, string $userId, string $exportJobId): void
    {
        $this->tenantBinder->runIfKnown($tenantId, function () use ($userId, $exportJobId): void {
            $this->finalizeJob($userId, $exportJobId);
        });
    }

    private function finalizeJob(string $userId, string $exportJobId): void
    {
        $exportJob = ExportJob::query()->whereKey($exportJobId)->first();

        if (!$exportJob instanceof ExportJob) {
            return;
        }

        $completed = $exportJob->result_path !== null;

        $exportJob->forceFill([
            'status' => $completed ? ExportJobStatus::Completed : ExportJobStatus::Failed,
            'finished_at' => now(),
        ])->save();

        if (!$completed) {
            return;
        }

        $user = User::query()
            ->active()
            ->whereKey($userId)
            ->first();

        if ($user instanceof User) {
            $user->notify(new ExportCompletedNotification($exportJob));
        }
    }
}
