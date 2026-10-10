<?php

declare(strict_types=1);

namespace App\Workflows\Engine;

use App\Contracts\Engine\BulkActionWorkflowInterface;
use App\Contracts\Engine\FinalizeBulkActivityInterface;
use App\Contracts\Engine\ProcessBulkChunkActivityInterface;
use App\DTOs\Engine\BulkActionData;
use App\Support\Maintenance\MaintenanceRetryOptions;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Throwable;

class BulkActionWorkflow implements BulkActionWorkflowInterface
{
    public function run(BulkActionData $input): Generator
    {
        $chunkActivity = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(10))
            ->withRetryOptions(MaintenanceRetryOptions::make())
            ->build(ProcessBulkChunkActivityInterface::class);

        $finalize = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(5))
            ->build(FinalizeBulkActivityInterface::class);

        foreach ($input->chunks as $chunk) {
            try {
                yield $chunkActivity->processBulkChunk(
                    $input->action,
                    $input->tenantId,
                    $input->actingUserId,
                    $input->objectTypeId,
                    $chunk,
                    $input->payload,
                    $input->bulkRunId,
                );
            } catch (Throwable) {
            }
        }

        $affectedIds = $input->chunks === []
            ? []
            : array_values(array_unique(array_merge(...$input->chunks)));

        yield $finalize->finalizeBulk(
            $input->tenantId,
            $input->actingUserId,
            $input->bulkRunId,
            $affectedIds,
            $input->threshold,
            $input->objectTypeSlug,
            $input->notify,
        );
    }
}
