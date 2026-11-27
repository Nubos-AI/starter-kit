<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Models\WebhookSubscription;

class ResetWebhookFailuresAction
{
    public function execute(string $subscriptionId, string $tenantId): void
    {
        WebhookSubscription::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereKey($subscriptionId)
            ->update(['consecutive_failures' => 0]);
    }
}
