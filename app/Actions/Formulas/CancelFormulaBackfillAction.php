<?php

declare(strict_types=1);

namespace App\Actions\Formulas;

use App\Contracts\Formulas\FormulaBackfillWorkflowInterface;
use App\Enums\Formulas\BackfillStatus;
use App\Models\FormulaBackfillRun;
use App\Support\Formulas\FormulaBackfillProgress;
use App\Support\Formulas\FormulaBackfillStarter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Temporal\Client\WorkflowClientInterface;
use Throwable;

class CancelFormulaBackfillAction
{
    public function __construct(
        private readonly FormulaBackfillProgress $progress,
        private readonly WorkflowClientInterface $workflowClient,
    ) {}

    public function execute(FormulaBackfillRun $run): bool
    {
        Gate::authorize('update', $run->objectType);

        $tenantId = $run->tenant_id;
        $runId = (string) $run->getKey();

        if (!$this->progress->finalize($tenantId, $runId, BackfillStatus::Cancelled)) {
            return false;
        }

        $workflowId = FormulaBackfillStarter::workflowId($tenantId, $run->field_definition_id);

        try {
            $stub = $this->workflowClient->newRunningWorkflowStub(
                FormulaBackfillWorkflowInterface::class,
                $workflowId,
            );

            $stub->cancel();
        } catch (Throwable $cause) {
            Log::warning('Formula backfill cancel signal could not be delivered.', [
                'backfill_run_id' => $runId,
                'workflow_id' => $workflowId,
                'reason' => $cause->getMessage(),
            ]);
        }

        return true;
    }
}
