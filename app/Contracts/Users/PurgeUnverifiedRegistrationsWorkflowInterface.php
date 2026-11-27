<?php

declare(strict_types=1);

namespace App\Contracts\Users;

use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface PurgeUnverifiedRegistrationsWorkflowInterface
{
    #[WorkflowMethod(name: 'PurgeUnverifiedRegistrationsWorkflow')]
    public function run(): Generator;
}
