<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\BulkDispatcherInterface;
use App\DTOs\Engine\BulkActionData;

class SynchronousBulkDispatcher implements BulkDispatcherInterface
{
    public function __construct(
        private readonly BulkChunkRunner $runner,
        private readonly BulkFinalizer $finalizer,
    ) {}

    public function start(BulkActionData $input): void
    {
        foreach ($input->chunks as $chunk) {
            $this->runner->run(
                $input->action,
                $input->tenantId,
                $input->actingUserId,
                $input->objectTypeId,
                $chunk,
                $input->payload,
                $input->bulkRunId,
            );
        }

        $affectedIds = $input->chunks === []
            ? []
            : array_values(array_unique(array_merge(...$input->chunks)));

        $this->finalizer->finalize(
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
