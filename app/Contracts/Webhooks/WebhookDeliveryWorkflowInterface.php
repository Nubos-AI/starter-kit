<?php

declare(strict_types=1);

namespace App\Contracts\Webhooks;

use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Enums\Webhooks\DeliveryOutcome;
use Generator;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface WebhookDeliveryWorkflowInterface
{
    #[WorkflowMethod(name: 'WebhookDeliveryWorkflow')]
    #[ReturnType(DeliveryOutcome::class)]
    public function deliver(WebhookDeliveryData $input): Generator;
}
