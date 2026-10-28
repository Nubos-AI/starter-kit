<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\RecordBackfillWorkflowInterface;
use App\DTOs\Engine\RecordBackfillData;
use App\Enums\Engine\RecordBackfillKind;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\WorkflowIdConflictPolicy;

class RecordBackfillStarter
{
    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    /**
     * @return non-empty-string
     */
    public static function workflowId(string $tenantId, RecordBackfillKind $kind, string $objectTypeId): string
    {
        return "record-backfill:{$tenantId}:{$kind->value}:{$objectTypeId}";
    }

    public function start(RecordBackfillData $input): void
    {
        $stub = $this->workflowClient->newWorkflowStub(
            RecordBackfillWorkflowInterface::class,
            WorkflowOptions::new()
                ->withWorkflowId(self::workflowId($input->tenantId, $input->kind, $input->objectTypeId))
                ->withTaskQueue((string) config('temporal.queue'))
                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
                ->withWorkflowIdConflictPolicy(WorkflowIdConflictPolicy::TerminateExisting),
        );

        $this->workflowClient->start($stub, $input);
    }
}
