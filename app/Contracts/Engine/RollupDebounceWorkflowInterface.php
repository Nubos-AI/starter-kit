<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use Generator;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface RollupDebounceWorkflowInterface
{
    #[WorkflowMethod(name: 'RollupDebounceWorkflow')]
    public function run(string $tenantId, string $objectTypeId, string $recordId): Generator;

    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    #[SignalMethod]
    public function enqueue(array $changedFieldKeys): void;

    #[SignalMethod]
    public function enqueueOwn(): void;
}
