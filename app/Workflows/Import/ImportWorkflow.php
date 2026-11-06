<?php

declare(strict_types=1);

namespace App\Workflows\Import;

use App\Contracts\Import\FinalizeImportActivityInterface;
use App\Contracts\Import\ImportWorkflowInterface;
use App\Contracts\Import\ProcessImportChunkActivityInterface;
use App\DTOs\Import\ImportData;
use App\Support\Maintenance\MaintenanceRetryOptions;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Throwable;

class ImportWorkflow implements ImportWorkflowInterface
{
    /** @var positive-int */
    private int $maxChunkAttempts = 3;

    public function run(ImportData $input): Generator
    {
        $chunk = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(10))
            ->withRetryOptions(MaintenanceRetryOptions::make()->withMaximumAttempts($this->maxChunkAttempts))
            ->build(ProcessImportChunkActivityInterface::class);

        $finalize = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(5))
            ->build(FinalizeImportActivityInterface::class);

        for ($offset = 0; $offset < $input->totalRows; $offset += $input->chunkSize) {
            try {
                yield $chunk->processImportChunk(
                    $input->tenantId,
                    $input->actingUserId,
                    $input->importJobId,
                    $input->disk,
                    $input->path,
                    $input->format,
                    $input->sheet,
                    $offset,
                    $input->chunkSize,
                );
            } catch (Throwable $exception) {
                yield $finalize->finalizeImport(
                    $input->tenantId,
                    $input->actingUserId,
                    $input->importJobId,
                    failed: true,
                );

                throw $exception;
            }
        }

        yield $finalize->finalizeImport(
            $input->tenantId,
            $input->actingUserId,
            $input->importJobId,
        );
    }
}
