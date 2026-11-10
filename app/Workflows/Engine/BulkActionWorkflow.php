<?php

declare(strict_types=1);

namespace App\Workflows\Engine;

use App\Contracts\Engine\BulkActionWorkflowInterface;
use App\Contracts\Engine\FinalizeBulkActivityInterface;
use App\Contracts\Engine\ProcessBulkChunkActivityInterface;
use App\DTOs\Engine\BulkActionData;
use App\Support\Temporal\BoundedActivityOptions;
use Carbon\CarbonInterval;
use Generator;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow;

class BulkActionWorkflow implements BulkActionWorkflowInterface
{
    public function run(BulkActionData $input): Generator
    {
        $chunkActivity = BoundedActivityOptions::make(CarbonInterval::minutes(10))
            ->build(ProcessBulkChunkActivityInterface::class);

        $finalize = BoundedActivityOptions::make(CarbonInterval::minutes(5))
            ->build(FinalizeBulkActivityInterface::class);

        $failedChunks = [];

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
            } catch (ActivityFailure $failure) {
                $failedChunks[] = $chunk;

                $this->logChunkFailure($input, $chunk, $failure);
            }
        }

        $affectedIds = $input->chunks === []
            ? []
            : array_values(array_unique(array_merge(...$input->chunks)));

        if ($failedChunks !== []) {
            $affectedIds = array_values(array_diff($affectedIds, array_merge(...$failedChunks)));
        }

        yield $finalize->finalizeBulk(
            $input->tenantId,
            $input->actingUserId,
            $input->bulkRunId,
            $affectedIds,
            $input->threshold,
            $input->objectTypeSlug,
            $input->notify,
            $failedChunks,
        );

        Workflow::getLogger()->info('Bulk action finished.', [
            ...$this->logContext($input),
            'failed_chunk_count' => count($failedChunks),
        ]);
    }

    /**
     * @param  list<string>  $chunk
     */
    private function logChunkFailure(BulkActionData $input, array $chunk, ActivityFailure $failure): void
    {
        Workflow::getLogger()->error('Bulk action chunk failed; its records are reported as failed.', [
            ...$this->logContext($input),
            'record_count' => count($chunk),
            'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
        ]);
    }

    /**
     * @return array{workflow_id: string, run_id: string|null, bulk_run_id: string, tenant_id: string, action: string}
     */
    private function logContext(BulkActionData $input): array
    {
        $execution = Workflow::getInfo()->execution;

        return [
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            'bulk_run_id' => $input->bulkRunId,
            'tenant_id' => $input->tenantId,
            'action' => $input->action,
        ];
    }
}
