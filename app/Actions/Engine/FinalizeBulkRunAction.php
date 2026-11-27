<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Support\Engine\BulkBatchReport;

class FinalizeBulkRunAction
{
    /**
     * @param  list<list<string>>  $failedChunks
     */
    public function execute(string $reportKey, array $failedChunks = []): void
    {
        $this->reportFailedChunks($reportKey, $failedChunks);

        BulkBatchReport::markFinished($reportKey);
    }

    /**
     * @param  list<list<string>>  $failedChunks
     */
    private function reportFailedChunks(string $reportKey, array $failedChunks): void
    {
        if ($failedChunks === [] || (BulkBatchReport::progress($reportKey)['finished'] ?? false)) {
            return;
        }

        $message = __('i18n.backend.support.engine.bulk_chunk_runner.the_record_could_not_be_processed_please_try_again');

        BulkBatchReport::addErrors($reportKey, array_map(
            static fn (string $recordId): array => ['recordId' => $recordId, 'message' => $message],
            array_values(array_unique(array_merge(...$failedChunks))),
        ));

        BulkBatchReport::markChunksFailed($reportKey, count($failedChunks));
    }
}
