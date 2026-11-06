<?php

declare(strict_types=1);

namespace App\Activities\Outbox;

use App\Contracts\Outbox\DispatchOutboxMessagesActivityInterface;
use App\Support\Outbox\OutboxMessageDispatcher;
use Illuminate\Support\Facades\Log;
use Temporal\Activity;
use Throwable;

class DispatchOutboxMessagesActivity implements DispatchOutboxMessagesActivityInterface
{
    public function __construct(private readonly OutboxMessageDispatcher $dispatcher) {}

    /**
     * @throws Throwable
     */
    public function dispatchMessages(): int
    {
        $execution = $this->workflowExecution();

        try {
            $outcome = $this->dispatcher->dispatchPending($execution);
        } catch (Throwable $exception) {
            Log::error('Outbox dispatch failed.', ['exception' => $exception::class, ...$execution]);

            throw $exception;
        }

        Log::info('Outbox entries dispatched.', [
            'handled' => $outcome->handled,
            'retried' => $outcome->retried,
            'failed' => $outcome->failed,
            ...$execution,
        ]);

        return $outcome->settled();
    }

    /**
     * @return array{workflow_id: string|null, run_id: string|null}
     */
    private function workflowExecution(): array
    {
        $execution = Activity::getInfo()->workflowExecution;

        return ['workflow_id' => $execution?->getID(), 'run_id' => $execution?->getRunID()];
    }
}
