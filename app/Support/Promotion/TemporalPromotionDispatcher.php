<?php

declare(strict_types=1);

namespace App\Support\Promotion;

use App\Contracts\Promotion\PromotionDispatcherInterface;
use App\Contracts\Promotion\PromotionWorkflowInterface;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Promotion\PromotionRunData;
use App\DTOs\Temporal\ProcessStartData;
use App\Models\PromotionRun;
use App\Support\Temporal\ProcessId;
use Temporal\Common\IdReusePolicy;

class TemporalPromotionDispatcher implements PromotionDispatcherInterface
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    public function start(PromotionRun $run): void
    {
        $tenantId = $run->tenant_id;
        $runId = (string) $run->getKey();

        $this->processes->start(new ProcessStartData(
            workflow: PromotionWorkflowInterface::class,
            workflowId: ProcessId::forTenant('promotion', $tenantId, $runId),
            arguments: [new PromotionRunData(
                tenantId: $tenantId,
                promotionRunId: $runId,
                actingUserId: $run->triggered_by_id,
            )],
            reusePolicy: IdReusePolicy::RejectDuplicate,
        ));
    }
}
