<?php

declare(strict_types=1);

namespace App\Workflows\Outbox;

use App\Contracts\Outbox\DispatchOutboxActivityInterface;
use App\Contracts\Outbox\DispatchOutboxWorkflowInterface;
use App\DTOs\Outbox\OutboxClaim;
use App\DTOs\Outbox\OutboxDelivery;
use App\DTOs\Outbox\OutboxDispatchOutcome;
use App\DTOs\Outbox\OutboxSettlementData;
use App\Support\Maintenance\MaintenanceRetryOptions;
use App\Support\Temporal\BoundedActivityOptions;
use Carbon\CarbonInterval;
use Generator;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;

class DispatchOutboxWorkflow implements DispatchOutboxWorkflowInterface
{
    public function dispatch(): Generator
    {
        $outcome = new OutboxDispatchOutcome(handled: 0, retried: 0, failed: 0);
        $maxBatches = max(1, (int) config('engine.outbox.max_batches_per_run'));

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            /** @var OutboxClaim $claim */
            $claim = yield $this->steps()->claimOutboxMessages();

            if (!$claim->isEmpty()) {
                $settlement = yield from $this->deliver($claim);

                /** @var OutboxDispatchOutcome $settled */
                $settled = yield $this->steps()->settleOutboxMessages($settlement);
                $outcome = $outcome->plus($settled);
            }

            if (!$claim->hasMore) {
                break;
            }
        }

        Workflow::getLogger()->info('Outbox entries dispatched.', [
            ...$this->logContext(),
            'handled' => $outcome->handled,
            'retried' => $outcome->retried,
            'failed' => $outcome->failed,
        ]);

        return $outcome->settled();
    }

    /**
     * @return Generator<mixed, mixed, mixed, OutboxSettlementData>
     */
    private function deliver(OutboxClaim $claim): Generator
    {
        $handledIds = [];
        $failedDeliveries = [];
        $releasedIds = [];

        foreach ($claim->deliveries as $delivery) {
            if (Workflow::now()->getTimestamp() >= $claim->leaseDeadlineTimestamp) {
                $releasedIds = [...$releasedIds, ...$delivery->entryIds];

                continue;
            }

            if (yield from $this->handledByEveryHandler($delivery)) {
                $handledIds = [...$handledIds, ...$delivery->entryIds];

                continue;
            }

            $failedDeliveries[] = ['entry_ids' => $delivery->entryIds, 'attempts' => $delivery->message->attempts];
        }

        return new OutboxSettlementData($handledIds, $failedDeliveries, $releasedIds);
    }

    /**
     * @return Generator<mixed, mixed, mixed, bool>
     */
    private function handledByEveryHandler(OutboxDelivery $delivery): Generator
    {
        $isHandled = true;

        foreach ($delivery->handlerClasses as $handlerClass) {
            try {
                yield $this->handlers()->handleOutboxMessage($handlerClass, $delivery->message);
            } catch (ActivityFailure $failure) {
                $isHandled = false;

                Workflow::getLogger()->warning('Outbox handler failed; the entry is retried.', [
                    ...$this->logContext(),
                    'handler' => $handlerClass,
                    'topic' => $delivery->message->topic,
                    'tenant_id' => $delivery->message->tenantId,
                    'subject_id' => $delivery->message->subject->id,
                    'attempts' => $delivery->message->attempts,
                    'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
                ]);
            }
        }

        return $isHandled;
    }

    /**
     * @return ActivityProxy<DispatchOutboxActivityInterface>
     */
    private function steps(): ActivityProxy
    {
        return BoundedActivityOptions::make(CarbonInterval::seconds((int) config('temporal.processes.step_start_to_close_seconds')))
            ->build(DispatchOutboxActivityInterface::class);
    }

    /**
     * @return ActivityProxy<DispatchOutboxActivityInterface>
     */
    private function handlers(): ActivityProxy
    {
        return BoundedActivityOptions::make(CarbonInterval::seconds((int) config('temporal.processes.step_start_to_close_seconds')))
            ->withRetryOptions(MaintenanceRetryOptions::make()->withMaximumAttempts(max(1, (int) config('engine.outbox.handler_max_attempts'))))
            ->build(DispatchOutboxActivityInterface::class);
    }

    /**
     * @return array{workflow_id: string, run_id: string|null}
     */
    private function logContext(): array
    {
        $execution = Workflow::getInfo()->execution;

        return ['workflow_id' => $execution->getID(), 'run_id' => $execution->getRunID()];
    }
}
