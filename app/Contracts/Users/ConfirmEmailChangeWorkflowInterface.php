<?php

declare(strict_types=1);

namespace App\Contracts\Users;

use App\DTOs\Users\SealedUserValueData;
use Generator;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\UpdateMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface ConfirmEmailChangeWorkflowInterface
{
    #[WorkflowMethod(name: 'ConfirmEmailChangeWorkflow')]
    public function run(SealedUserValueData $input): Generator;

    #[UpdateMethod(name: 'confirmEmailChange')]
    #[ReturnType('bool')]
    public function confirmEmailChange(SealedUserValueData $input): Generator;
}
