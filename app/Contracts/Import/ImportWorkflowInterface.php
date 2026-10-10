<?php

declare(strict_types=1);

namespace App\Contracts\Import;

use App\DTOs\Import\ImportData;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface ImportWorkflowInterface
{
    #[WorkflowMethod(name: 'ImportWorkflow')]
    public function run(ImportData $input): Generator;
}
