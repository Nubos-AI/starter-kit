<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Contracts\Reports\EnsureReportIndexesWorkflowInterface;
use App\DTOs\Reports\ReportDefinitionData;
use App\Support\Engine\IndexRegistry;
use Illuminate\Support\Facades\Log;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\Common\WorkflowIdConflictPolicy;
use Throwable;

class EnsureReportIndexesStarter
{
    public function __construct(
        private readonly WorkflowClientInterface $workflowClient,
        private readonly ReportIndexPlanner $planner,
        private readonly IndexRegistry $indexRegistry,
    ) {}

    public static function workflowId(string $indexName): string
    {
        return "report-index:{$indexName}";
    }

    public function start(ReportDefinitionData $definition, string $reportId): void
    {
        if (!config('reports.index_maintenance.enabled')) {
            return;
        }

        try {
            foreach ($this->planner->plan($definition) as $field) {
                $this->workflowClient->start(
                    $this->newStub(self::workflowId($this->indexRegistry->indexNameFor($field))),
                    $definition->objectTypeId,
                    $field->key,
                );
            }
        } catch (Throwable $throwable) {
            Log::warning('Report expression index maintenance could not be started.', [
                'report_id' => $reportId,
                'object_type_id' => $definition->objectTypeId,
                'reason' => $throwable->getMessage(),
            ]);
        }
    }

    private function newStub(string $workflowId): object
    {
        return $this->workflowClient->newWorkflowStub(
            EnsureReportIndexesWorkflowInterface::class,
            WorkflowOptions::new()
                ->withWorkflowId($workflowId)
                ->withTaskQueue((string) config('temporal.queue'))
                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
                ->withWorkflowIdConflictPolicy(WorkflowIdConflictPolicy::UseExisting),
        );
    }
}
