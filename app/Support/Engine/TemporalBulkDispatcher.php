<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\BulkActionWorkflowInterface;
use App\Contracts\Engine\BulkDispatcherInterface;
use App\DTOs\Engine\BulkActionData;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;

class TemporalBulkDispatcher implements BulkDispatcherInterface
{
    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    public function start(BulkActionData $input): void
    {
        $stub = $this->workflowClient->newWorkflowStub(
            BulkActionWorkflowInterface::class,
            WorkflowOptions::new()
                ->withWorkflowId("bulk:{$input->tenantId}:{$input->bulkRunId}")
                ->withTaskQueue((string) config('temporal.queue')),
        );

        $this->workflowClient->start($stub, $input);
    }
}
