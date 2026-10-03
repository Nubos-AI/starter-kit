<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Enums\Webhooks\WebhookEventType;
use App\Models\AuditEntry;
use App\Models\CustomRecord;
use App\Models\OutboxEvent;
use App\Models\WebhookSubscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookChangeDispatcher
{
    public function __construct(
        private readonly WebhookSubscriptionMatcher $matcher,
        private readonly WebhookDeliveryStarter $starter,
    ) {}

    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    public function dispatch(string $tenantId, string $objectTypeId, string $recordId, int $version, int $sequence, array $changedFieldKeys): void
    {
        TenantContext::withTenantId($tenantId, function () use ($tenantId, $objectTypeId, $recordId, $version, $sequence, $changedFieldKeys): void {
            $outbox = OutboxEvent::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sequence', $sequence)
                ->first();

            if (!$outbox instanceof OutboxEvent) {
                return;
            }

            $type = $this->deriveEventType($tenantId, $recordId, $version, $changedFieldKeys);

            $record = CustomRecord::query()
                ->withTrashed()
                ->where('tenant_id', $tenantId)
                ->whereKey($recordId)
                ->with('objectType')
                ->first();

            if (!$record instanceof CustomRecord) {
                return;
            }

            $subscriptions = $this->matcher->match($tenantId, $objectTypeId, $type);

            foreach ($subscriptions as $subscription) {
                $this->startOne($subscription, $record, $type, $sequence, $outbox);
            }
        });
    }

    private function startOne(
        WebhookSubscription $subscription,
        CustomRecord $record,
        WebhookEventType $type,
        int $sequence,
        OutboxEvent $outbox,
    ): void {
        try {
            $this->starter->start(
                $subscription,
                $record,
                $type,
                $sequence,
                $outbox->created_at,
                $outbox->id,
            );
        } catch (Throwable $throwable) {
            Log::warning('Webhook delivery start failed for a subscription; sibling subscriptions are unaffected.', [
                'subscription_id' => $subscription->getKey(),
                'event_id' => $outbox->id,
                'reason' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    private function deriveEventType(string $tenantId, string $recordId, int $version, array $changedFieldKeys): WebhookEventType
    {
        if (in_array('deleted_at', $changedFieldKeys, true)) {
            $newValue = AuditEntry::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('auditable_type', (new CustomRecord)->getMorphClass())
                ->where('auditable_id', $recordId)
                ->where('version', $version)
                ->where('field_key', 'deleted_at')
                ->value('new_value');

            return $newValue !== null ? WebhookEventType::RecordDeleted : WebhookEventType::RecordRestored;
        }

        if ($version === 1) {
            return WebhookEventType::RecordCreated;
        }

        if (count($changedFieldKeys) === 1) {
            $event = config('modules.webhooks.change_events.'.$changedFieldKeys[0]);
            if (is_string($event) && ($type = WebhookEventType::tryFrom($event)) !== null) {
                return $type;
            }
        }

        return WebhookEventType::RecordUpdated;
    }
}
