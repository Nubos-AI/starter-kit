<?php

declare(strict_types=1);

namespace App\Contracts\Promotion;

use App\DTOs\Promotion\PromotionRunData;
use Generator;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface PromotionWorkflowInterface
{
    #[WorkflowMethod(name: 'PromotionWorkflow')]
    public function run(PromotionRunData $input): Generator;
}
