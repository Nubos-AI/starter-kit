<?php

declare(strict_types=1);

namespace App\Workflows\Engine;

use App\Contracts\Engine\EvaluateRecordConsumersActivityInterface;
use App\Contracts\Engine\RecomputeRollupsActivityInterface;
use App\Contracts\Engine\RecordChangeRelayWorkflowInterface;
use App\Contracts\Engine\RelayOutboxActivityInterface;
use App\Contracts\Notifications\EvaluateNotificationRulesActivityInterface;
use App\Contracts\Outbox\DispatchOutboxMessagesActivityInterface;
use App\Contracts\Webhooks\DispatchWebhookDeliveriesActivityInterface;
use App\DTOs\Engine\RecordChangeBatch;
use App\Exceptions\Outbox\OutboxClaimLostException;
use App\Support\Temporal\RecordActivityOptions;
use Generator;
use Temporal\Exception\Failure\ActivityFailure;

class RecordChangeRelayWorkflow implements RecordChangeRelayWorkflowInterface
{
    private int $maxBatches = 10;

    public function dispatch(): Generator
    {
        $relay = RecordActivityOptions::make()->build(RelayOutboxActivityInterface::class);

        $processed = 0;

        for ($batch = 0; $batch < $this->maxBatches; $batch++) {
            $changes = yield $relay->publishOutbox();

            if ($changes->isEmpty()) {
                break;
            }

            $processed += yield from $this->consume($changes);

            if ($changes->relayBatchId === null) {
                break;
            }

            yield $relay->acknowledgeOutbox($changes->relayBatchId, $changes->count());
        }

        $topics = RecordActivityOptions::make()->build(DispatchOutboxMessagesActivityInterface::class);

        yield from $this->isolate(fn (): Generator => yield $topics->dispatchMessages());

        return $processed;
    }

    private function consume(RecordChangeBatch $changes): Generator
    {
        $triggers = RecordActivityOptions::make()->build(EvaluateRecordConsumersActivityInterface::class);
        $rules = RecordActivityOptions::make()->build(EvaluateNotificationRulesActivityInterface::class);
        $webhooks = RecordActivityOptions::make()->build(DispatchWebhookDeliveriesActivityInterface::class);
        $rollups = RecordActivityOptions::make()->build(RecomputeRollupsActivityInterface::class);

        yield from $this->renew($changes);
        $processed = yield from $this->consumeActivity($changes, fn (): Generator => yield $triggers->evaluateTriggers($changes));

        yield from $this->renew($changes);
        yield from $this->consumeActivity($changes, fn (): Generator => yield $rules->evaluateRules($changes));
        yield from $this->renew($changes);
        yield from $this->consumeActivity($changes, fn (): Generator => yield $webhooks->dispatchWebhooks($changes));
        yield from $this->renew($changes);
        yield from $this->consumeActivity($changes, fn (): Generator => yield $rollups->recomputeRollups($changes));

        return is_int($processed) ? $processed : $changes->count();
    }

    /** @param callable(): Generator $consumer */
    private function consumeActivity(RecordChangeBatch $changes, callable $consumer): Generator
    {
        if ($changes->relayBatchId === null) {
            return yield from $this->isolate($consumer);
        }

        return yield from $consumer();
    }

    private function renew(RecordChangeBatch $changes): Generator
    {
        if ($changes->relayBatchId === null) {
            return;
        }

        $relay = RecordActivityOptions::make()->build(RelayOutboxActivityInterface::class);
        $owned = yield $relay->renewOutbox($changes->relayBatchId, $changes->count());

        if (!$owned) {
            throw new OutboxClaimLostException;
        }
    }

    /**
     * @param  callable(): Generator  $consumer
     */
    private function isolate(callable $consumer): Generator
    {
        try {
            return yield from $consumer();
        } catch (ActivityFailure) {
            return null;
        }
    }
}
