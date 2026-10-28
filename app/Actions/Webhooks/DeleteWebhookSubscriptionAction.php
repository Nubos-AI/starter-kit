<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Models\WebhookSubscription;

class DeleteWebhookSubscriptionAction
{
    public function execute(WebhookSubscription $subscription): void
    {
        $subscription->delete();
    }
}
