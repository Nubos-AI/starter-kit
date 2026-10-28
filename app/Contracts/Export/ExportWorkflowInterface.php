<?php

declare(strict_types=1);

namespace App\Contracts\Export;

use App\DTOs\Export\ExportData;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface ExportWorkflowInterface
{
    #[WorkflowMethod(name: 'ExportWorkflow')]
    public function run(ExportData $input): Generator;
}
