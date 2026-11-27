<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class RecordWebhookFailureAction
{
    public function execute(string $subscriptionId, string $tenantId, bool $isPermanent): bool
    {
        if ($this->subscription($subscriptionId, $tenantId)->increment('consecutive_failures') === 0) {
            return false;
        }

        $subscription = $this->subscription($subscriptionId, $tenantId)->first();

        if (!$subscription instanceof WebhookSubscription) {
            return false;
        }

        if (!$isPermanent && $subscription->consecutive_failures < (int) config('webhooks.auto_disable.consecutive_failure_threshold')) {
            return false;
        }

        $disabled = $this->subscription($subscriptionId, $tenantId)
            ->where('status', WebhookSubscriptionStatus::Active->value)
            ->update(['status' => WebhookSubscriptionStatus::Disabled->value]);

        if ($disabled === 0) {
            return false;
        }

        Log::warning('A webhook subscription was disabled after failed deliveries.', [
            'tenant_id' => $tenantId,
            'subscription_id' => $subscriptionId,
            'consecutive_failures' => $subscription->consecutive_failures,
            'is_permanent' => $isPermanent,
        ]);

        return true;
    }

    /**
     * @return Builder<WebhookSubscription>
     */
    private function subscription(string $subscriptionId, string $tenantId): Builder
    {
        return WebhookSubscription::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereKey($subscriptionId);
    }
}
