<?php

declare(strict_types=1);

namespace App\Contracts\Goals;

use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface GoalProgressScanWorkflowInterface
{
    #[WorkflowMethod(name: 'GoalProgressScanWorkflow')]
    public function run(): Generator;
}
