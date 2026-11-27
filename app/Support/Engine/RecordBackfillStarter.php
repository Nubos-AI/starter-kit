<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\RecordBackfillWorkflowInterface;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Engine\RecordBackfillData;
use App\DTOs\Temporal\ProcessStartData;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\WorkflowIdConflictPolicy;

class RecordBackfillStarter
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    /**
     * @return non-empty-string
     */
    public static function workflowId(string $tenantId, string $kind, string $objectTypeId): string
    {
        return "record-backfill:{$tenantId}:{$kind}:{$objectTypeId}";
    }

    public function start(RecordBackfillData $input): void
    {
        $this->processes->start(new ProcessStartData(
            workflow: RecordBackfillWorkflowInterface::class,
            workflowId: self::workflowId($input->tenantId, $input->kind, $input->objectTypeId),
            arguments: [$input],
            reusePolicy: IdReusePolicy::AllowDuplicate,
            conflictPolicy: WorkflowIdConflictPolicy::TerminateExisting,
        ));
    }
}
