<?php

declare(strict_types=1);

namespace App\Activities\Webhooks;

use App\Contracts\Webhooks\DeliverWebhookActivityInterface;
use App\DTOs\Webhooks\WebhookBasicAuth;
use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Enums\Webhooks\DeliveryOutcome;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Exceptions\Modules\OutboundBlockedException;
use App\Models\WebhookSubscription;
use App\Support\Tenancy\TenantBinder;
use App\Support\Webhooks\WebhookEgressClient;
use App\Support\Webhooks\WebhookFailureTracker;
use App\Support\Webhooks\WebhookSignature;
use App\Traits\Temporal\DescribesWorkflowExecution;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeliverWebhookActivity implements DeliverWebhookActivityInterface
{
    use DescribesWorkflowExecution;

    private int $goneStatus = 410;

    public function __construct(
        private readonly WebhookEgressClient $egressClient,
        private readonly WebhookSignature $signature,
        private readonly WebhookFailureTracker $failureTracker,
        private readonly TenantBinder $tenantBinder,
    ) {}

    public function deliverWebhook(WebhookDeliveryData $input): DeliveryOutcome
    {
        $subscriptionId = $input->subscriptionId;
        $tenantId = $input->tenantId;

        $subscription = WebhookSubscription::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereKey($subscriptionId)
            ->first();

        if (!$subscription instanceof WebhookSubscription || $subscription->status !== WebhookSubscriptionStatus::Active) {
            return $this->finished($input, DeliveryOutcome::Skipped);
        }

        $outcome = $this->tenantBinder->runIfKnown(
            $tenantId,
            fn (): DeliveryOutcome => $this->deliver($input, $subscription),
        );

        return $this->finished($input, $outcome instanceof DeliveryOutcome ? $outcome : DeliveryOutcome::Skipped);
    }

    private function finished(WebhookDeliveryData $input, DeliveryOutcome $outcome): DeliveryOutcome
    {
        Log::info('Webhook delivery finished.', [
            ...$this->deliveryContext($input),
            'outcome' => $outcome->value,
        ]);

        return $outcome;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function deliveryContext(WebhookDeliveryData $input): array
    {
        return [
            'tenant_id' => $input->tenantId,
            'subscription_id' => $input->subscriptionId,
            'event_id' => $input->eventId,
            'event_type' => $input->eventType,
            ...$this->workflowExecution(),
        ];
    }

    private function deliver(WebhookDeliveryData $input, WebhookSubscription $subscription): DeliveryOutcome
    {
        $eventId = $input->eventId;
        $rawBody = $input->rawBody;

        $timestamp = Carbon::now()->getTimestamp();

        $headers = [
            'X-Signature' => $this->signature->sign($rawBody, $subscription->secret, $timestamp),
            'X-Event-Id' => $eventId,
            'X-Event-Type' => $input->eventType,
            'X-Event-Sequence' => (string) $input->sequence,
        ];

        try {
            $response = $this->egressClient->post(
                $subscription->target_url,
                $rawBody,
                $headers,
                WebhookBasicAuth::forSubscription($subscription),
            );
        } catch (OutboundBlockedException) {
            return DeliveryOutcome::Suppressed;
        } catch (ConnectionException $exception) {
            Log::warning('Webhook delivery could not reach its target.', [
                ...$this->deliveryContext($input),
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        return $this->classify($input, $response);
    }

    public function recordDeliveryFailure(string $subscriptionId, string $tenantId, string $eventId): void
    {
        Log::warning('Webhook delivery gave up after its retries.', [
            'tenant_id' => $tenantId,
            'subscription_id' => $subscriptionId,
            'event_id' => $eventId,
            ...$this->workflowExecution(),
        ]);

        $this->failureTracker->recordFailure($subscriptionId, $tenantId);
    }

    private function classify(WebhookDeliveryData $input, Response $response): DeliveryOutcome
    {
        $subscriptionId = $input->subscriptionId;
        $tenantId = $input->tenantId;
        $status = $response->status();

        $outcome = match (true) {
            $status >= 200 && $status < 300 => DeliveryOutcome::Delivered,
            $status >= 400 && $status < 500 => DeliveryOutcome::Parked,
            default => null,
        };

        if ($outcome === DeliveryOutcome::Delivered) {
            $this->failureTracker->reset($subscriptionId, $tenantId);

            return DeliveryOutcome::Delivered;
        }

        if ($outcome === DeliveryOutcome::Parked) {
            $this->recordRejection($subscriptionId, $tenantId, $status);

            return DeliveryOutcome::Parked;
        }

        Log::warning('Webhook delivery failed.', [
            ...$this->deliveryContext($input),
            'status' => $status,
        ]);

        throw new RuntimeException(
            "Webhook delivery to subscription \"{$subscriptionId}\" failed with status {$status}.",
        );
    }

    private function recordRejection(string $subscriptionId, string $tenantId, int $status): void
    {
        if ($status === $this->goneStatus) {
            $this->failureTracker->disablePermanently($subscriptionId, $tenantId);

            return;
        }

        $this->failureTracker->recordFailure($subscriptionId, $tenantId);
    }
}
