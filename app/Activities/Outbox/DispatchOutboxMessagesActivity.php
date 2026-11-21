<?php

declare(strict_types=1);

namespace App\Activities\Outbox;

use App\Contracts\Outbox\DispatchOutboxMessagesActivityInterface;
use App\Support\Outbox\OutboxMessageDispatcher;
use App\Traits\Temporal\DescribesWorkflowExecution;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchOutboxMessagesActivity implements DispatchOutboxMessagesActivityInterface
{
    use DescribesWorkflowExecution;

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
}
