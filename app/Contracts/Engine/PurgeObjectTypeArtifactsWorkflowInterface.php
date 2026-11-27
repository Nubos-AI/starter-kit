<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Engine\ObjectTypePurgeData;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface PurgeObjectTypeArtifactsWorkflowInterface
{
    #[WorkflowMethod(name: 'PurgeObjectTypeArtifactsWorkflow')]
    public function run(ObjectTypePurgeData $input): Generator;
}
