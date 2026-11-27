<?php

declare(strict_types=1);

namespace App\Handlers\Engine;

use App\Contracts\Engine\FieldMaintenanceWorkflowInterface;
use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Engine\FieldMaintenanceData;
use App\DTOs\Outbox\OutboxMessage;
use App\DTOs\Temporal\ProcessStartData;
use App\Enums\Outbox\OutboxTopic;
use App\Support\Temporal\ProcessId;
use Temporal\Common\IdReusePolicy;

class FieldMaintenanceOutboxHandler implements IdempotentOutboxHandler
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    public function topic(): string
    {
        return OutboxTopic::FieldDefinitionChanged->value;
    }

    public function handle(OutboxMessage $message): void
    {
        $fieldDefinitionId = $message->subject->id;

        $this->processes->start(new ProcessStartData(
            workflow: FieldMaintenanceWorkflowInterface::class,
            workflowId: ProcessId::forTenant('field-maintenance', $message->tenantId, $fieldDefinitionId, (string) ($message->payload['request_id'] ?? '')),
            arguments: [new FieldMaintenanceData(
                tenantId: $message->tenantId,
                fieldDefinitionId: $fieldDefinitionId,
                syncIndexes: ($message->payload['sync_indexes'] ?? false) === true,
                backfillRollups: ($message->payload['backfill_rollups'] ?? false) === true,
            )],
            reusePolicy: IdReusePolicy::AllowDuplicateFailedOnly,
        ));
    }
}
