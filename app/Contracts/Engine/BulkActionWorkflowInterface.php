<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Engine\BulkActionData;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface BulkActionWorkflowInterface
{
    #[WorkflowMethod(name: 'BulkActionWorkflow')]
    public function run(BulkActionData $input): Generator;
}
