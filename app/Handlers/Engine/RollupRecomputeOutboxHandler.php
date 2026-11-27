<?php

declare(strict_types=1);

namespace App\Handlers\Engine;

use App\Contracts\Engine\RollupDebounceWorkflowInterface;
use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Outbox\OutboxMessage;
use App\DTOs\Temporal\ProcessStartData;
use App\Enums\Outbox\OutboxTopic;
use App\Support\Temporal\ProcessId;

class RollupRecomputeOutboxHandler implements IdempotentOutboxHandler
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    public function topic(): string
    {
        return OutboxTopic::RollupRecomputeRequested->value;
    }

    public function handle(OutboxMessage $message): void
    {
        $recordId = $message->subject->id;
        $objectTypeId = (string) ($message->payload['object_type_id'] ?? '');
        $changedFieldKeys = array_values(array_map('strval', (array) ($message->payload['changed_field_keys'] ?? [])));

        $process = new ProcessStartData(
            workflow: RollupDebounceWorkflowInterface::class,
            workflowId: ProcessId::forTenant('rollup', $message->tenantId, $recordId),
            arguments: [$message->tenantId, $objectTypeId, $recordId],
        );

        if (($message->payload['is_cascade'] ?? false) === true) {
            $this->processes->startWithSignal($process, 'enqueue', [$changedFieldKeys]);
        }

        if (($message->payload['is_own'] ?? false) === true) {
            $this->processes->startWithSignal($process, 'enqueueOwn');
        }
    }
}
