<?php

declare(strict_types=1);

namespace App\Contracts\Approvals;

use App\DTOs\Approvals\ApprovalCommandData;
use App\DTOs\Approvals\ApprovalProcessData;
use Generator;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\UpdateMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface ApprovalProcessWorkflowInterface
{
    #[WorkflowMethod(name: 'ApprovalProcessWorkflow')]
    public function run(ApprovalProcessData $input): Generator;

    #[SignalMethod(name: 'stageChanged')]
    public function stageChanged(): void;

    #[UpdateMethod(name: 'decide')]
    #[ReturnType('string')]
    public function decide(ApprovalCommandData $command): Generator;

    #[UpdateMethod(name: 'cancel')]
    #[ReturnType('string')]
    public function cancel(ApprovalCommandData $command): Generator;
}
