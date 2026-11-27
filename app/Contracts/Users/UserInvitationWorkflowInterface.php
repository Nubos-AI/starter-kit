<?php

declare(strict_types=1);

namespace App\Contracts\Users;

use App\DTOs\Users\UserInvitationData;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface UserInvitationWorkflowInterface
{
    #[WorkflowMethod(name: 'UserInvitationWorkflow')]
    public function run(UserInvitationData $input): Generator;
}
