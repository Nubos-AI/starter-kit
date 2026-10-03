<?php

declare(strict_types=1);

namespace App\Workflows\Engine;

use App\Contracts\Engine\EvaluateRecordConsumersActivityInterface;
use App\Contracts\Engine\RecomputeRollupsActivityInterface;
use App\Contracts\Engine\RecordChangeRelayWorkflowInterface;
use App\Contracts\Engine\RelayOutboxActivityInterface;
use App\Contracts\Notifications\EvaluateNotificationRulesActivityInterface;
use App\Contracts\Webhooks\DispatchWebhookDeliveriesActivityInterface;
use App\Support\Temporal\RecordActivityOptions;
use Generator;
use Temporal\Exception\Failure\ActivityFailure;

class RecordChangeRelayWorkflow implements RecordChangeRelayWorkflowInterface
{
    public function dispatch(): Generator
    {
        $relay = RecordActivityOptions::make()->build(RelayOutboxActivityInterface::class);

        $changes = yield $relay->publishOutbox();

        if ($changes->isEmpty()) {
            return 0;
        }

        $triggers = RecordActivityOptions::make()->build(EvaluateRecordConsumersActivityInterface::class);
        $rules = RecordActivityOptions::make()->build(EvaluateNotificationRulesActivityInterface::class);
        $webhooks = RecordActivityOptions::make()->build(DispatchWebhookDeliveriesActivityInterface::class);
        $rollups = RecordActivityOptions::make()->build(RecomputeRollupsActivityInterface::class);

        $processed = yield from $this->isolate(fn (): Generator => yield $triggers->evaluateTriggers($changes));

        yield from $this->isolate(fn (): Generator => yield $rules->evaluateRules($changes));
        yield from $this->isolate(fn (): Generator => yield $webhooks->dispatchWebhooks($changes));
        yield from $this->isolate(fn (): Generator => yield $rollups->recomputeRollups($changes));

        return is_int($processed) ? $processed : $changes->count();
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
