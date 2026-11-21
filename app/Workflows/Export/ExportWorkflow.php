<?php

declare(strict_types=1);

namespace App\Workflows\Export;

use App\Contracts\Export\ExportRecordsActivityInterface;
use App\Contracts\Export\ExportWorkflowInterface;
use App\Contracts\Export\FinalizeExportActivityInterface;
use App\DTOs\Export\ExportData;
use App\Support\Temporal\BoundedActivityOptions;
use Carbon\CarbonInterval;
use Generator;
use Temporal\Workflow;
use Throwable;

class ExportWorkflow implements ExportWorkflowInterface
{
    public function run(ExportData $input): Generator
    {
        $export = BoundedActivityOptions::make(CarbonInterval::minutes(30))
            ->build(ExportRecordsActivityInterface::class);

        $finalize = BoundedActivityOptions::make(CarbonInterval::minutes(2))
            ->build(FinalizeExportActivityInterface::class);

        try {
            yield $export->exportRecords($input->tenantId, $input->actingUserId, $input->exportJobId);
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Export failed; the export job is being finalized as failed.', [
                ...$this->logContext($input),
                'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
            ]);

            yield $finalize->finalizeExport($input->tenantId, $input->actingUserId, $input->exportJobId);

            throw $failure;
        }

        yield $finalize->finalizeExport($input->tenantId, $input->actingUserId, $input->exportJobId);

        Workflow::getLogger()->info('Export finished.', $this->logContext($input));
    }

    /**
     * @return array{workflow_id: string, run_id: string|null, tenant_id: string, export_job_id: string}
     */
    private function logContext(ExportData $input): array
    {
        $execution = Workflow::getInfo()->execution;

        return [
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            'tenant_id' => $input->tenantId,
            'export_job_id' => $input->exportJobId,
        ];
    }
}
