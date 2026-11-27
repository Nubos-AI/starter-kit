<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\Enums\Import\ImportJobStatus;
use App\Models\ImportJob;
use App\Support\Import\ImportErrorReport;
use App\Support\Import\PreparedImportRows;

class FinalizeImportAction
{
    public function __construct(private readonly PreparedImportRows $preparedRows) {}

    public function execute(string $tenantId, string $importJobId, bool $failed = false): bool
    {
        $importJob = ImportJob::query()->whereKey($importJobId)->first();

        if (!$importJob instanceof ImportJob) {
            return false;
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

        $this->preparedRows->delete($importJob);

        return !$failed;
    }
}
