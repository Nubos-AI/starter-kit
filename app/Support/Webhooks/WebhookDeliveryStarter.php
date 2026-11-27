<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Contracts\Temporal\ProcessStarterInterface;
use App\Contracts\Webhooks\WebhookDeliveryWorkflowInterface;
use App\DTOs\Temporal\ProcessStartData;
use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Enums\Webhooks\WebhookEventType;
use App\Models\CustomRecord;
use App\Models\WebhookSubscription;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\Encrypter;

class WebhookDeliveryStarter
{
    public function __construct(
        private readonly ProcessStarterInterface $processes,
        private readonly WebhookPayloadBuilder $payloadBuilder,
        private readonly Encrypter $encrypter,
    ) {}

    public function start(
        WebhookSubscription $subscription,
        CustomRecord $record,
        WebhookEventType|string $type,
        int $sequence,
        CarbonInterface $occurredAt,
        string $eventId,
    ): void {
        $payload = $this->payloadBuilder->build($type, $record, $subscription, $sequence, $occurredAt);

        $rawBody = (string) json_encode($payload);

        $input = WebhookDeliveryData::sealed(
            encrypter: $this->encrypter,
            subscriptionId: (string) $subscription->getKey(),
            tenantId: $subscription->tenant_id,
            eventId: $eventId,
            eventType: WebhookEventType::valueOf($type),
            sequence: $sequence,
            rawBody: $rawBody,
        );

        $eventType = WebhookEventType::valueOf($type);
        $workflowId = "webhook-delivery-{$subscription->getKey()}-{$eventType}-{$sequence}";

        $this->processes->start(new ProcessStartData(
            workflow: WebhookDeliveryWorkflowInterface::class,
            workflowId: $workflowId,
            arguments: [$input],
        ));
    }
}
