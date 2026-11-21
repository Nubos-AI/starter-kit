<?php

declare(strict_types=1);

namespace App\Contracts\Trash;

use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface PurgeTrashedRecordsWorkflowInterface
{
    #[WorkflowMethod(name: 'PurgeTrashedRecordsWorkflow')]
    public function run(): Generator;
}
