<?php

declare(strict_types=1);

namespace App\Contracts\Reports;

use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface EnsureReportIndexesWorkflowInterface
{
    #[WorkflowMethod(name: 'EnsureReportIndexesWorkflow')]
    public function run(string $objectTypeId, string $fieldKey): Generator;
}
