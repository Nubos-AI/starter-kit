<?php

declare(strict_types=1);

namespace App\Contracts\Reminders;

use App\DTOs\Reminders\DueReminderCase;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface DueReminderWorkflowInterface
{
    #[WorkflowMethod(name: 'DueReminderWorkflow')]
    public function run(DueReminderCase $case): Generator;
}
