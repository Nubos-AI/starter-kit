<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Enums\Authorization\RoleAuthority;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Notifications\WebhookNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class WebhookFailureTracker
{
    public function reset(string $subscriptionId, string $tenantId): void
    {
        $this->scoped($subscriptionId, $tenantId)->update(['consecutive_failures' => 0]);
    }

    public function recordFailure(string $subscriptionId, string $tenantId): bool
    {
        if ($this->scoped($subscriptionId, $tenantId)->increment('consecutive_failures') === 0) {
            return false;
        }

        $subscription = $this->scoped($subscriptionId, $tenantId)->first();

        if (!$subscription instanceof WebhookSubscription) {
            return false;
        }

        if ($subscription->consecutive_failures < $this->threshold()) {
            return false;
        }

        $disabled = $this->scoped($subscriptionId, $tenantId)
            ->where('status', WebhookSubscriptionStatus::Active->value)
            ->update(['status' => WebhookSubscriptionStatus::Disabled->value]);

        if ($disabled === 0) {
            return false;
        }

        $this->notifyAdministrators($subscription);

        return true;
    }

    /**
     * @return Builder<WebhookSubscription>
     */
    private function scoped(string $subscriptionId, string $tenantId): Builder
    {
        return WebhookSubscription::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereKey($subscriptionId);
    }

    private function threshold(): int
    {
        return (int) config('webhooks.auto_disable.consecutive_failure_threshold');
    }

    private function notifyAdministrators(WebhookSubscription $subscription): void
    {
        $recipients = $this->recipients($subscription);

        if ($recipients->isEmpty()) {
            return;
        }

        TenantContext::withTenantId($subscription->tenant_id, function () use ($recipients, $subscription): void {
            Notification::send($recipients, new WebhookNotification(
                (string) $subscription->getKey(),
                $subscription->target_url,
                $subscription->consecutive_failures,
            ));
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(WebhookSubscription $subscription): Collection
    {
        $administratorIds = RoleAssignment::query()
            ->where('model_type', (new User)->getMorphClass())
            ->whereIn('role_id', Role::query()
                ->whereIn('authority', [RoleAuthority::SuperAdmin->value, RoleAuthority::ScopeAdmin->value])
                ->select('id'))
            ->pluck('model_id');

        $candidateIds = $administratorIds
            ->push($subscription->service_user_id)
            ->filter(fn (?string $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        if ($candidateIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->whereKey($candidateIds->all())
            ->get();
    }
}
