<?php

declare(strict_types=1);

namespace App\Contracts\Notifications;

use App\DTOs\Notifications\DateRuleCase;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface DateRuleNotificationWorkflowInterface
{
    #[WorkflowMethod(name: 'DateRuleNotificationWorkflow')]
    public function run(DateRuleCase $case): Generator;
}
