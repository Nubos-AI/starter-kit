<?php

declare(strict_types=1);

namespace App\Contracts\Webhooks;

use App\Enums\Webhooks\WebhookSubscriptionStatus;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'webhook.')]
interface WebhookChallengeActivityInterface
{
    #[ActivityMethod(name: 'challengeSubscription')]
    public function challengeSubscription(string $tenantId, string $subscriptionId): WebhookSubscriptionStatus;
}
