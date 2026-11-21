<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\Enums\Import\ImportDuplicateMode;
use App\Enums\Import\ImportJobStatus;
use App\Models\ImportJob;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Import\ImportReaderFactory;
use App\Support\Import\ImportRowWriter;
use App\Support\Import\PreparedImportRows;
use App\Support\Reports\ReportExecutionContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcessImportChunkAction
{
    private int $headerRowNumber = 1;

    public function __construct(
        private readonly ReportExecutionContext $context,
        private readonly ImportReaderFactory $readerFactory,
        private readonly ImportRowWriter $writer,
        private readonly PreparedImportRows $preparedRows,
    ) {}

    public function execute(
        string $tenantId,
        string $actingUserId,
        string $importJobId,
        string $disk,
        string $path,
        string $format,
        ?string $sheet,
        int $offset,
        int $limit,
    ): void {
        $this->context->runAsViewer($tenantId, $actingUserId, function (User $actingUser) use (
            $tenantId,
            $importJobId,
            $sheet,
            $offset,
            $limit,
        ): void {
            $importJob = ImportJob::query()->whereKey($importJobId)->first();

            if (!$importJob instanceof ImportJob || $importJob->status !== ImportJobStatus::Running) {
                return;
            }

            $objectType = ObjectType::query()->whereKey($importJob->object_type_id)->firstOrFail();

            $this->preparedRows->prepare($importJob, fn (): iterable => $this->readerFactory->make(
                $importJob->source_disk,
                $importJob->source_path,
                $importJob->format,
                $sheet,
                $actingUser,
            )->rows());

            $start = max($offset, (int) $importJob->processed_rows);
            $end = min($offset + $limit, $importJob->total_rows);

            foreach ($this->preparedRows->rows($importJob, $start, max(0, $end - $start)) as $index => $row) {
                $this->processRow($importJobId, $objectType, $tenantId, $index, $row);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function processRow(string $importJobId, ObjectType $objectType, string $tenantId, int $index, array $row): void
    {
        DB::transaction(function () use ($importJobId, $objectType, $tenantId, $index, $row): void {
            $job = ImportJob::query()->whereKey($importJobId)->lockForUpdate()->firstOrFail();

            if ($job->status !== ImportJobStatus::Running || $job->processed_rows > $index) {
                return;
            }

            if ($job->processed_rows !== $index) {
                throw new RuntimeException('Import chunks must be processed in order.');
            }

            $outcome = $this->writer->write(
                $row,
                $objectType,
                $job->mapping,
                ImportDuplicateMode::from($job->duplicate_mode),
                $tenantId,
                $this->headerRowNumber + 1 + $index,
                $importJobId,
            );
            $counter = match ($outcome) {
                'created' => 'created_count',
                'updated' => 'updated_count',
                'error' => 'error_count',
                default => null,
            };
            $attributes = ['processed_rows' => $index + 1];

            if ($counter !== null) {
                $attributes[$counter] = (int) $job->getAttribute($counter) + 1;
            }

            $job->forceFill($attributes)->save();
        });
    }
}
