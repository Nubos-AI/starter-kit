<?php

declare(strict_types=1);

namespace App\Contracts\Webhooks;

use App\Enums\Webhooks\WebhookSubscriptionStatus;
use Generator;
use Temporal\Workflow\ReturnType;
use Temporal\Workflow\UpdateMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface WebhookChallengeWorkflowInterface
{
    #[WorkflowMethod(name: 'WebhookChallengeWorkflow')]
    public function run(string $tenantId, string $subscriptionId): Generator;

    #[UpdateMethod(name: 'challengeOutcome')]
    #[ReturnType(WebhookSubscriptionStatus::class)]
    public function challengeOutcome(): Generator;
}
