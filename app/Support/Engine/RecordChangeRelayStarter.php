<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\RecordChangeRelayWorkflowInterface;
use Illuminate\Support\Facades\Log;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;
use Throwable;

class RecordChangeRelayStarter
{
    private string $workflowId = 'automation-relay-immediate';

    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    public function startNow(): void
    {
        if (!config('record-processing.schedules.relay_immediate')) {
            return;
        }

        try {
            $stub = $this->workflowClient->newWorkflowStub(
                RecordChangeRelayWorkflowInterface::class,
                WorkflowOptions::new()
                    ->withWorkflowId($this->workflowId)
                    ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
                    ->withTaskQueue((string) config('temporal.queue')),
            );

            $this->workflowClient->start($stub);
        } catch (WorkflowExecutionAlreadyStartedException) {
            return;
        } catch (Throwable $throwable) {
            Log::warning('Immediate automation relay start failed; the schedule backstop will drain the outbox.', [
                'reason' => $throwable->getMessage(),
            ]);
        }
    }
}
