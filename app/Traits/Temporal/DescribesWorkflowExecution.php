<?php

declare(strict_types=1);

namespace App\Traits\Temporal;

use Illuminate\Support\Facades\Log;
use Temporal\Activity;
use Temporal\Exception\OutOfContextException;

trait DescribesWorkflowExecution
{
    /**
     * @return array{workflow_id: string|null, run_id: string|null}
     */
    private function workflowExecution(): array
    {
        try {
            $execution = Activity::getInfo()->workflowExecution;
        } catch (OutOfContextException) {
            Log::debug('The workflow execution is unknown because the code runs outside an activity context.');

            return ['workflow_id' => null, 'run_id' => null];
        }

        return ['workflow_id' => $execution?->getID(), 'run_id' => $execution?->getRunID()];
    }
}
