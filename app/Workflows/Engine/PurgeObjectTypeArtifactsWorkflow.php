<?php

declare(strict_types=1);

namespace App\Workflows\Engine;

use App\Contracts\Engine\PurgeObjectTypeArtifactsActivityInterface;
use App\Contracts\Engine\PurgeObjectTypeArtifactsWorkflowInterface;
use App\DTOs\Engine\ObjectTypePurgeData;
use App\Support\Temporal\BoundedActivityOptions;
use Carbon\CarbonInterval;
use Generator;
use Temporal\Workflow;
use Throwable;

class PurgeObjectTypeArtifactsWorkflow implements PurgeObjectTypeArtifactsWorkflowInterface
{
    public function run(ObjectTypePurgeData $input): Generator
    {
        $steps = BoundedActivityOptions::make(CarbonInterval::seconds(max(1, (int) config('engine.field_maintenance.index_start_to_close_seconds'))))
            ->build(PurgeObjectTypeArtifactsActivityInterface::class);

        try {
            yield $steps->dropObjectTypeIndexes($input);

            $deleted = $input->files === [] ? 0 : yield $steps->deleteObjectTypeFiles($input);
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Object type artifact purge failed.', [
                ...$this->logContext($input),
                'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
            ]);

            throw $failure;
        }

        Workflow::getLogger()->info('Object type artifact purge finished.', [...$this->logContext($input), 'deleted_files' => $deleted]);
    }

    /**
     * @return array{workflow_id: string, run_id: string|null, tenant_id: string, object_type_id: string}
     */
    private function logContext(ObjectTypePurgeData $input): array
    {
        $execution = Workflow::getInfo()->execution;

        return [
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            'tenant_id' => $input->tenantId,
            'object_type_id' => $input->objectTypeId,
        ];
    }
}
