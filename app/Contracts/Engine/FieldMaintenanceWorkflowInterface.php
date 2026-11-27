<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Engine\FieldMaintenanceData;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface FieldMaintenanceWorkflowInterface
{
    #[WorkflowMethod(name: 'FieldMaintenanceWorkflow')]
    public function run(FieldMaintenanceData $input): Generator;
}
