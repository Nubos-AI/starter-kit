<?php

declare(strict_types=1);

namespace App\Workflows\Engine;

use App\Contracts\Engine\FieldMaintenanceActivityInterface;
use App\Contracts\Engine\FieldMaintenanceWorkflowInterface;
use App\DTOs\Engine\FieldMaintenanceData;
use App\Support\Temporal\BoundedActivityOptions;
use Carbon\CarbonInterval;
use Generator;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Throwable;

class FieldMaintenanceWorkflow implements FieldMaintenanceWorkflowInterface
{
    public function run(FieldMaintenanceData $input): Generator
    {
        try {
            if ($input->syncIndexes) {
                yield $this->steps('engine.field_maintenance.index_start_to_close_seconds')->syncFieldIndexes($input->tenantId, $input->fieldDefinitionId);
            }

            if ($input->backfillRollups) {
                yield $this->steps('engine.field_maintenance.backfill_start_to_close_seconds')->requestRollupBackfill($input->tenantId, $input->fieldDefinitionId);
            }
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Field maintenance failed.', [
                ...$this->logContext($input),
                'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
            ]);

            throw $failure;
        }

        Workflow::getLogger()->info('Field maintenance finished.', $this->logContext($input));
    }

    /**
     * @return ActivityProxy<FieldMaintenanceActivityInterface>
     */
    private function steps(string $timeoutKey): ActivityProxy
    {
        return BoundedActivityOptions::make(CarbonInterval::seconds(max(1, (int) config($timeoutKey))))
            ->build(FieldMaintenanceActivityInterface::class);
    }

    /**
     * @return array{workflow_id: string, run_id: string|null, tenant_id: string, field_definition_id: string}
     */
    private function logContext(FieldMaintenanceData $input): array
    {
        $execution = Workflow::getInfo()->execution;

        return [
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            'tenant_id' => $input->tenantId,
            'field_definition_id' => $input->fieldDefinitionId,
        ];
    }
}
