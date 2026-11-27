<?php

declare(strict_types=1);

namespace App\Contracts\Outbox;

use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface DispatchOutboxWorkflowInterface
{
    #[WorkflowMethod(name: 'DispatchOutboxWorkflow')]
    public function dispatch(): Generator;
}
