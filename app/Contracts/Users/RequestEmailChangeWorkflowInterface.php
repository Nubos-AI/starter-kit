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
interface RequestEmailChangeWorkflowInterface
{
    #[WorkflowMethod(name: 'RequestEmailChangeWorkflow')]
    public function run(SealedUserValueData $input): Generator;

    #[UpdateMethod(name: 'requestEmailChange')]
    #[ReturnType('bool')]
    public function requestEmailChange(SealedUserValueData $input): Generator;
}
