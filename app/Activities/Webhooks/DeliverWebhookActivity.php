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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class DeliverWebhookActivity implements DeliverWebhookActivityInterface
{
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
            return DeliveryOutcome::Skipped;
        }

        $outcome = $this->tenantBinder->runIfKnown(
            $tenantId,
            fn (): DeliveryOutcome => $this->deliver($input, $subscription),
        );

        if ($outcome instanceof DeliveryOutcome) {
            return $outcome;
        }

        return DeliveryOutcome::Skipped;
    }

    private function deliver(WebhookDeliveryData $input, WebhookSubscription $subscription): DeliveryOutcome
    {
        $subscriptionId = $input->subscriptionId;
        $tenantId = $input->tenantId;
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
        } catch (Throwable $exception) {
            throw $exception;
        }

        return $this->classify($subscriptionId, $tenantId, $response);
    }

    public function recordDeliveryFailure(string $subscriptionId, string $tenantId, string $eventId): void
    {
        $this->failureTracker->recordFailure($subscriptionId, $tenantId);
    }

    private function classify(
        string $subscriptionId,
        string $tenantId,
        Response $response,
    ): DeliveryOutcome {
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
            return DeliveryOutcome::Parked;
        }

        throw new RuntimeException(
            "Webhook delivery to subscription \"{$subscriptionId}\" failed with status {$status}.",
        );
    }
}
