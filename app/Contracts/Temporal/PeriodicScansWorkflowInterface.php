<?php

declare(strict_types=1);

namespace App\Contracts\Temporal;

use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface PeriodicScansWorkflowInterface
{
    #[WorkflowMethod(name: 'PeriodicScansWorkflow')]
    public function scan(): Generator;
}
