<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\Import\ImportDuplicateMode;
use App\Models\ImportJob;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Reports\ReportExecutionContext;

class ImportChunkProcessor
{
    private int $headerRowNumber = 1;

    public function __construct(
        private readonly ReportExecutionContext $context,
        private readonly ImportReaderFactory $readerFactory,
        private readonly ImportRowWriter $writer,
    ) {}

    public function process(
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
            $disk,
            $path,
            $format,
            $sheet,
            $offset,
            $limit,
        ): void {
            $importJob = ImportJob::query()->whereKey($importJobId)->first();

            if (!$importJob instanceof ImportJob) {
                return;
            }

            $objectType = ObjectType::query()->whereKey($importJob->object_type_id)->firstOrFail();

            $reader = $this->readerFactory->make($disk, $path, $format, $sheet, $actingUser);

            /** @var array<string, mixed> $mapping */
            $mapping = $importJob->mapping;
            $mode = ImportDuplicateMode::from($importJob->duplicate_mode);

            $created = 0;
            $updated = 0;
            $errors = 0;

            $index = 0;

            foreach ($reader->rows() as $row) {
                if ($index < $offset) {
                    $index++;

                    continue;
                }

                if ($index >= $offset + $limit) {
                    break;
                }

                $rowNumber = $this->headerRowNumber + 1 + $index;
                $index++;

                /** @var array<string, mixed> $row */
                $outcome = $this->writer->write($row, $objectType, $mapping, $mode, $tenantId, $rowNumber, $importJobId);

                match ($outcome) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'error' => $errors++,
                    default => null,
                };
            }

            $this->persistCounters($importJob, $created, $updated, $errors);
        });
    }

    private function persistCounters(ImportJob $importJob, int $created, int $updated, int $errors): void
    {
        if ($created > 0) {
            $importJob->increment('created_count', $created);
        }

        if ($updated > 0) {
            $importJob->increment('updated_count', $updated);
        }

        if ($errors > 0) {
            $importJob->increment('error_count', $errors);
        }
    }
}
