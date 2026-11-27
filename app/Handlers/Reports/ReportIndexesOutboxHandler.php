<?php

declare(strict_types=1);

namespace App\Handlers\Reports;

use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\Contracts\Reports\EnsureReportIndexesWorkflowInterface;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Outbox\OutboxMessage;
use App\DTOs\Temporal\ProcessStartData;
use App\Enums\Outbox\OutboxTopic;
use App\Support\Temporal\ProcessId;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\WorkflowIdConflictPolicy;

class ReportIndexesOutboxHandler implements IdempotentOutboxHandler
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    public function topic(): string
    {
        return OutboxTopic::ReportIndexesRequested->value;
    }

    public function handle(OutboxMessage $message): void
    {
        $objectTypeId = (string) ($message->payload['object_type_id'] ?? '');

        foreach ((array) ($message->payload['indexes'] ?? []) as $index) {
            if (!is_array($index) || !is_string($index['field_key'] ?? null) || !is_string($index['index_name'] ?? null)) {
                continue;
            }

            $this->processes->start(new ProcessStartData(
                workflow: EnsureReportIndexesWorkflowInterface::class,
                workflowId: ProcessId::global('report-index', $index['index_name']),
                arguments: [$objectTypeId, $index['field_key']],
                reusePolicy: IdReusePolicy::AllowDuplicate,
                conflictPolicy: WorkflowIdConflictPolicy::UseExisting,
            ));
        }
    }
}
