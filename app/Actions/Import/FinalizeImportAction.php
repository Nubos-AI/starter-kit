<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\Enums\Import\ImportJobStatus;
use App\Models\ImportJob;
use App\Models\User;
use App\Notifications\ImportCompletedNotification;
use App\Support\Import\ImportErrorReport;
use App\Support\Import\PreparedImportRows;
use App\Support\Tenancy\ActingUserContext;

class FinalizeImportAction
{
    public function __construct(
        private readonly ActingUserContext $context,
        private readonly PreparedImportRows $preparedRows,
    ) {}

    public function execute(string $tenantId, string $actingUserId, string $importJobId, bool $failed = false): void
    {
        $this->context->run($tenantId, $actingUserId, function () use ($tenantId, $importJobId, $failed): void {
            $importJob = ImportJob::query()->whereKey($importJobId)->first();

            if (!$importJob instanceof ImportJob) {
                return;
            }

            $failed = $failed
                || $importJob->status === ImportJobStatus::Failed
                || (int) $importJob->processed_rows < (int) $importJob->total_rows;

            $errorReportPath = ImportErrorReport::persist($importJobId, $tenantId, $importJob->source_disk)
                ?? $importJob->error_report_path;

            $importJob->forceFill([
                'status' => $failed ? ImportJobStatus::Failed : ImportJobStatus::Completed,
                'error_report_path' => $errorReportPath,
                'finished_at' => now(),
            ])->save();

            $user = User::query()->whereKey($importJob->user_id)->first();

            if (!$failed && $user instanceof User) {
                $user->notify(new ImportCompletedNotification($importJob));
            }

            $this->preparedRows->delete($importJob);
        });
    }
}
