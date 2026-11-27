<?php

declare(strict_types=1);

namespace App\Actions\Export;

use App\Enums\Export\ExportJobStatus;
use App\Models\ExportJob;

class FinalizeExportAction
{
    public function execute(string $exportJobId): bool
    {
        $exportJob = ExportJob::query()->whereKey($exportJobId)->first();

        if (!$exportJob instanceof ExportJob) {
            return false;
        }

        $isCompleted = $exportJob->result_path !== null;

        $exportJob->forceFill([
            'status' => $isCompleted ? ExportJobStatus::Completed : ExportJobStatus::Failed,
            'finished_at' => now(),
        ])->save();

        return $isCompleted;
    }
}
