<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use Generator;
use Temporal\DataConverter\Type;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface RecordChangeRelayWorkflowInterface
{
    #[WorkflowMethod(name: 'AutomationRelayWorkflow')]
    #[ReturnType(Type::TYPE_INT)]
    public function dispatch(): Generator;
}
