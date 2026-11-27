<?php

declare(strict_types=1);

namespace App\Support\Formulas;

use App\Contracts\Formulas\FormulaBackfillWorkflowInterface;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\DTOs\Formulas\FormulaBackfillData;
use App\DTOs\Temporal\ProcessStartData;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\WorkflowIdConflictPolicy;

class FormulaBackfillStarter
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    /**
     * @return non-empty-string
     */
    public static function workflowId(string $tenantId, string $fieldDefinitionId): string
    {
        return "formula-backfill:{$tenantId}:{$fieldDefinitionId}";
    }

    public function start(FormulaBackfillData $input): void
    {
        $this->processes->start(new ProcessStartData(
            workflow: FormulaBackfillWorkflowInterface::class,
            workflowId: self::workflowId($input->tenantId, $input->fieldDefinitionId),
            arguments: [$input],
            reusePolicy: IdReusePolicy::AllowDuplicate,
            conflictPolicy: WorkflowIdConflictPolicy::TerminateExisting,
        ));
    }
}
