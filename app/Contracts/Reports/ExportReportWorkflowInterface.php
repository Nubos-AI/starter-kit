<?php

declare(strict_types=1);

namespace App\Contracts\Reports;

use App\DTOs\Export\ExportData;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface ExportReportWorkflowInterface
{
    #[WorkflowMethod(name: 'ExportReportWorkflow')]
    public function run(ExportData $input): Generator;
}
