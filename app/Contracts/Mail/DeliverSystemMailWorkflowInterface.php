<?php

declare(strict_types=1);

namespace App\Contracts\Mail;

use App\DTOs\Mail\SystemMailData;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface DeliverSystemMailWorkflowInterface
{
    #[WorkflowMethod(name: 'DeliverSystemMailWorkflow')]
    public function deliver(SystemMailData $input): Generator;
}
