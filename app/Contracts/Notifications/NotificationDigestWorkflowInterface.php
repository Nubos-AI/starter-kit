<?php

declare(strict_types=1);

namespace App\Contracts\Notifications;

use App\DTOs\Notifications\NotificationDigestCase;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface NotificationDigestWorkflowInterface
{
    #[WorkflowMethod(name: 'NotificationDigestWorkflow')]
    public function run(NotificationDigestCase $case): Generator;
}
