<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\RollupDebounceWorkflowInterface;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;

class RollupDebounceStarter
{
    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    public function start(string $tenantId, string $objectTypeId, string $recordId, array $changedFieldKeys): void
    {
        $this->workflowClient->startWithSignal(
            $this->newStub($tenantId, $recordId),
            'enqueue',
            [$changedFieldKeys],
            [$tenantId, $objectTypeId, $recordId],
        );
    }

    public function startOwn(string $tenantId, string $objectTypeId, string $recordId): void
    {
        $this->workflowClient->startWithSignal(
            $this->newStub($tenantId, $recordId),
            'enqueueOwn',
            [],
            [$tenantId, $objectTypeId, $recordId],
        );
    }

    private function newStub(string $tenantId, string $recordId): object
    {
        return $this->workflowClient->newWorkflowStub(
            RollupDebounceWorkflowInterface::class,
            WorkflowOptions::new()
                ->withWorkflowId("rollup:{$tenantId}:{$recordId}")
                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
                ->withTaskQueue((string) config('temporal.queue')),
        );
    }
}
