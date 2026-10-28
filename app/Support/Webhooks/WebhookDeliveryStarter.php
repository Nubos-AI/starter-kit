<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Contracts\Webhooks\WebhookDeliveryWorkflowInterface;
use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Enums\Webhooks\WebhookEventType;
use App\Models\CustomRecord;
use App\Models\WebhookSubscription;
use Carbon\CarbonInterface;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;
use Throwable;

class WebhookDeliveryStarter
{
    public function __construct(
        private readonly WorkflowClientInterface $workflowClient,
        private readonly WebhookPayloadBuilder $payloadBuilder,
    ) {}

    public function start(
        WebhookSubscription $subscription,
        CustomRecord $record,
        WebhookEventType $type,
        int $sequence,
        CarbonInterface $occurredAt,
        string $eventId,
    ): void {
        $payload = $this->payloadBuilder->build($type, $record, $subscription, $sequence, $occurredAt);

        $rawBody = (string) json_encode($payload);

        $input = new WebhookDeliveryData(
            subscriptionId: (string) $subscription->getKey(),
            tenantId: $subscription->tenant_id,
            eventId: $eventId,
            eventType: $type->value,
            sequence: $sequence,
            rawBody: $rawBody,
        );

        $workflowId = "webhook-delivery-{$subscription->getKey()}-{$type->value}-{$sequence}";

        try {
            $stub = $this->workflowClient->newWorkflowStub(
                WebhookDeliveryWorkflowInterface::class,
                WorkflowOptions::new()
                    ->withWorkflowId($workflowId)
                    ->withTaskQueue((string) config('temporal.queue')),
            );

            $this->workflowClient->start($stub, $input);
        } catch (Throwable $throwable) {
            if ($this->isAlreadyStarted($throwable)) {
                return;
            }

            throw $throwable;
        }
    }

    private function isAlreadyStarted(Throwable $throwable): bool
    {
        return $throwable instanceof WorkflowExecutionAlreadyStartedException
            || str_contains($throwable::class, 'WorkflowExecutionAlreadyStarted');
    }
}
