<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Backfill\BackfillOutcome;
use App\DTOs\Engine\RecordBackfillData;
use Generator;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface RecordBackfillWorkflowInterface
{
    #[WorkflowMethod(name: 'RecordBackfillWorkflow')]
    #[ReturnType(BackfillOutcome::class)]
    public function run(RecordBackfillData $input): Generator;

    #[SignalMethod]
    public function cancel(): void;
}
