<?php

declare(strict_types=1);

namespace App\Support\Promotion;

use App\Contracts\Promotion\PromotionDispatcherInterface;
use App\Contracts\Promotion\PromotionWorkflowInterface;
use App\DTOs\Promotion\PromotionRunData;
use App\Models\PromotionRun;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;

class TemporalPromotionDispatcher implements PromotionDispatcherInterface
{
    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    public function start(PromotionRun $run): void
    {
        $tenantId = $run->tenant_id;
        $runId = (string) $run->getKey();

        $stub = $this->workflowClient->newWorkflowStub(
            PromotionWorkflowInterface::class,
            WorkflowOptions::new()
                ->withWorkflowId("promotion:{$tenantId}:{$runId}")
                ->withTaskQueue((string) config('temporal.queue')),
        );

        $this->workflowClient->start($stub, new PromotionRunData(
            tenantId: $tenantId,
            promotionRunId: $runId,
            actingUserId: $run->triggered_by_id,
        ));
    }
}
