<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Enums\Authorization\RoleAuthority;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Notifications\WebhookNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class NotifyWebhookDisabledAction
{
    public function execute(string $subscriptionId, string $tenantId, string $deliveryKey): void
    {
        $subscription = WebhookSubscription::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereKey($subscriptionId)
            ->first();

        if (!$subscription instanceof WebhookSubscription) {
            return;
        }

        $recipients = $this->recipients($subscription);

        foreach ($recipients as $recipient) {
            $notification = new WebhookNotification($subscriptionId, $subscription->target_url, $subscription->consecutive_failures);
            $notification->id = Uuid::uuid5(Uuid::fromString($deliveryKey), (string) $recipient->getKey())->toString();

            $recipient->notify($notification);
        }

        Log::info('Administrators were told about a disabled webhook subscription.', ['tenant_id' => $tenantId, 'subscription_id' => $subscriptionId, 'recipients' => $recipients->count()]);
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(WebhookSubscription $subscription): Collection
    {
        $administratorIds = RoleAssignment::query()
            ->where('model_type', (new User)->getMorphClass())
            ->whereIn('role_id', Role::withoutTenantScope()
                ->where('roles.tenant_id', $subscription->tenant_id)
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
            ->active()
            ->get();
    }
}
