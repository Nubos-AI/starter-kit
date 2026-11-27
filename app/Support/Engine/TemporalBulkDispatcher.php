<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\BulkActionWorkflowInterface;
use App\Contracts\Engine\BulkDispatcherInterface;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Engine\BulkActionData;
use App\DTOs\Temporal\ProcessStartData;

class TemporalBulkDispatcher implements BulkDispatcherInterface
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    public function start(BulkActionData $input): void
    {
        $this->processes->start(new ProcessStartData(
            workflow: BulkActionWorkflowInterface::class,
            workflowId: "bulk:{$input->tenantId}:{$input->bulkRunId}",
            arguments: [$input],
        ));
    }
}
