<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\Contracts\Formulas\FormulaBackfillWorkflowInterface;
use App\DTOs\Formulas\FormulaBackfillData;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\WorkflowIdConflictPolicy;

class FormulaBackfillStarter
{
    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    /**
     * @return non-empty-string
     */
    public static function workflowId(string $tenantId, string $fieldDefinitionId): string
    {
        return "formula-backfill:{$tenantId}:{$fieldDefinitionId}";
    }

    public function start(FormulaBackfillData $input): void
    {
        $stub = $this->workflowClient->newWorkflowStub(
            FormulaBackfillWorkflowInterface::class,
            WorkflowOptions::new()
                ->withWorkflowId(self::workflowId($input->tenantId, $input->fieldDefinitionId))
                ->withTaskQueue((string) config('temporal.queue'))
                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
                ->withWorkflowIdConflictPolicy(WorkflowIdConflictPolicy::TerminateExisting),
        );

        $this->workflowClient->start($stub, $input);
    }
}
