<?php

declare(strict_types=1);

namespace App\Contracts\Users;

use App\DTOs\Users\ChangeUserPasswordData;
use Generator;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\UpdateMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface ChangeUserPasswordWorkflowInterface
{
    #[WorkflowMethod(name: 'ChangeUserPasswordWorkflow')]
    public function run(ChangeUserPasswordData $input): Generator;

    #[UpdateMethod(name: 'setPassword')]
    #[ReturnType('bool')]
    public function setPassword(ChangeUserPasswordData $input): Generator;
}
