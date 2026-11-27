<?php

declare(strict_types=1);

namespace App\Handlers\Engine;

use App\Contracts\Engine\PurgeObjectTypeArtifactsWorkflowInterface;
use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Engine\ObjectTypePurgeData;
use App\DTOs\Outbox\OutboxMessage;
use App\DTOs\Temporal\ProcessStartData;
use App\Enums\Outbox\OutboxTopic;
use App\Support\Temporal\ProcessId;
use Temporal\Common\IdReusePolicy;

class ObjectTypePurgedOutboxHandler implements IdempotentOutboxHandler
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    public function topic(): string
    {
        return OutboxTopic::ObjectTypePurged->value;
    }

    public function handle(OutboxMessage $message): void
    {
        $input = ObjectTypePurgeData::fromTemporalPayload([
            'tenant_id' => $message->tenantId,
            'object_type_id' => $message->subject->id,
            'files' => $message->payload['files'] ?? [],
        ]);

        $this->processes->start(new ProcessStartData(
            workflow: PurgeObjectTypeArtifactsWorkflowInterface::class,
            workflowId: ProcessId::forTenant('object-type-purge', $input->tenantId, $input->objectTypeId),
            arguments: [$input],
            reusePolicy: IdReusePolicy::AllowDuplicateFailedOnly,
        ));
    }
}
