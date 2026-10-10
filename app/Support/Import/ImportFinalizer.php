<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\Import\ImportJobStatus;
use App\Models\ImportJob;
use App\Models\User;
use App\Notifications\ImportCompletedNotification;
use App\Support\Tenancy\ActingUserContext;

class ImportFinalizer
{
    public function __construct(private readonly ActingUserContext $context) {}

    public function finalize(string $tenantId, string $actingUserId, string $importJobId): void
    {
        $this->context->run($tenantId, $actingUserId, function () use ($tenantId, $importJobId): void {
            $importJob = ImportJob::query()->whereKey($importJobId)->first();

            if (!$importJob instanceof ImportJob) {
                return;
            }

            $errorReportPath = ImportErrorReport::persist($importJobId, $tenantId, $importJob->source_disk);

            $importJob->forceFill([
                'status' => ImportJobStatus::Completed,
                'error_report_path' => $errorReportPath,
                'finished_at' => now(),
            ])->save();

            $user = User::query()->whereKey($importJob->user_id)->first();

            if ($user instanceof User) {
                $user->notify(new ImportCompletedNotification($importJob));
            }
        });
    }
}
