<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Enums\Webhooks\WebhookEventType;
use App\Models\CustomRecord;
use App\Models\OutboxEvent;
use App\Models\Team;
use App\Models\WebhookSubscription;
use App\Support\Authorization\RowAccess\RecordAccessRuleCompiler;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookChangeDispatcher
{
    public function __construct(
        private readonly WebhookSubscriptionMatcher $matcher,
        private readonly WebhookDeliveryStarter $starter,
        private readonly TeamAccessRuleResolver $ruleResolver,
        private readonly RecordAccessRuleCompiler $ruleCompiler,
    ) {}

    /**
     * @param  array<int, string>  $changedFieldKeys
     *
     * @throws Throwable
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

            $record = CustomRecord::query()
                ->withTrashed()
                ->where('tenant_id', $tenantId)
                ->whereKey($recordId)
                ->with('objectType')
                ->first();

            if (!$record instanceof CustomRecord) {
                return;
            }

            $type = $this->deriveEventType($outbox, $record, $version, $changedFieldKeys);

            $subscriptions = $this->matcher->match($tenantId, $objectTypeId, $type);
            $firstFailure = null;

            foreach ($subscriptions as $subscription) {
                $failure = $this->startOne($subscription, $record, $type, $sequence, $outbox);
                $firstFailure ??= $failure;
            }

            if ($firstFailure instanceof Throwable) {
                throw $firstFailure;
            }
        });
    }

    private function startOne(
        WebhookSubscription $subscription,
        CustomRecord $record,
        WebhookEventType $type,
        int $sequence,
        OutboxEvent $outbox,
    ): ?Throwable {
        try {
            if (!$this->isWithinTeamAccess($subscription, $record, $outbox)) {
                return null;
            }

            $this->starter->start(
                $subscription,
                $record,
                $type,
                $sequence,
                $outbox->created_at,
                $outbox->id,
            );

            return null;
        } catch (Throwable $throwable) {
            Log::warning('Webhook delivery start failed for a subscription; sibling subscriptions are unaffected.', [
                'subscription_id' => $subscription->getKey(),
                'event_id' => $outbox->id,
                'exception' => $throwable::class,
            ]);

            return $throwable;
        }
    }

    private function isWithinTeamAccess(WebhookSubscription $subscription, CustomRecord $record, OutboxEvent $outbox): bool
    {
        if ($subscription->team_id === null) {
            return true;
        }

        $context = [
            'subscription_id' => $subscription->getKey(),
            'event_id' => $outbox->id,
            'record_id' => $record->getKey(),
            'team_id' => $subscription->team_id,
        ];

        $team = Team::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->whereKey($subscription->team_id)
            ->first();

        if (!$team instanceof Team) {
            Log::warning('Webhook delivery withheld; the subscription team no longer exists in the tenant.', $context);

            return false;
        }

        if ($this->ruleCompiler->admits($record, $this->ruleResolver->resolveForTeam($team))) {
            return true;
        }

        Log::info('Webhook delivery withheld; the record lies outside the access rules of the subscription team.', $context);

        return false;
    }

    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    private function deriveEventType(OutboxEvent $outbox, CustomRecord $record, int $version, array $changedFieldKeys): WebhookEventType
    {
        if (in_array('deleted_at', $changedFieldKeys, true)) {
            $isDeletion = $outbox->payload['deleted'] ?? $record->trashed();

            return $isDeletion === true ? WebhookEventType::RecordDeleted : WebhookEventType::RecordRestored;
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
