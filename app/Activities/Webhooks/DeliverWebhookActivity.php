<?php

declare(strict_types=1);

namespace App\Activities\Webhooks;

use App\Actions\Webhooks\DeliverWebhookAction;
use App\Actions\Webhooks\NotifyWebhookDisabledAction;
use App\Actions\Webhooks\RecordWebhookFailureAction;
use App\Actions\Webhooks\ResetWebhookFailuresAction;
use App\Contracts\Webhooks\DeliverWebhookActivityInterface;
use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Enums\Webhooks\DeliveryOutcome;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;

class DeliverWebhookActivity implements DeliverWebhookActivityInterface
{
    public function __construct(
        private readonly DeliverWebhookAction $deliver,
        private readonly ResetWebhookFailuresAction $resetFailures,
        private readonly RecordWebhookFailureAction $recordFailure,
        private readonly NotifyWebhookDisabledAction $notifyDisabled,
        private readonly ProcessStepRunner $steps,
        private readonly TenantBinder $tenantBinder,
    ) {}

    public function deliverWebhook(WebhookDeliveryData $input): DeliveryOutcome
    {
        return $this->steps->run(
            'webhooks.deliver',
            ['tenant_id' => $input->tenantId, 'subscription_id' => $input->subscriptionId, 'event_id' => $input->eventId],
            fn (): DeliveryOutcome => $this->tenantBinder->runIfKnown($input->tenantId, fn (): DeliveryOutcome => $this->deliver->execute($input)) ?? DeliveryOutcome::Skipped,
        );
    }

    public function resetDeliveryFailures(string $subscriptionId, string $tenantId): void
    {
        $this->steps->run(
            'webhooks.reset-delivery-failures',
            ['tenant_id' => $tenantId, 'subscription_id' => $subscriptionId],
            fn () => $this->resetFailures->execute($subscriptionId, $tenantId),
        );
    }

    public function recordDeliveryFailure(string $subscriptionId, string $tenantId, bool $isPermanent): bool
    {
        return $this->steps->run(
            'webhooks.record-delivery-failure',
            ['tenant_id' => $tenantId, 'subscription_id' => $subscriptionId, 'is_permanent' => $isPermanent],
            fn (): bool => $this->recordFailure->execute($subscriptionId, $tenantId, $isPermanent),
        );
    }

    public function notifySubscriptionDisabled(string $subscriptionId, string $tenantId, string $deliveryKey): void
    {
        $this->steps->run(
            'webhooks.notify-subscription-disabled',
            ['tenant_id' => $tenantId, 'subscription_id' => $subscriptionId],
            fn () => $this->tenantBinder->runIfKnown($tenantId, fn () => $this->notifyDisabled->execute($subscriptionId, $tenantId, $deliveryKey)),
        );
    }
}
