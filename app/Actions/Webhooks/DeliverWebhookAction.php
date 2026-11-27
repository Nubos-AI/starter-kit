<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\DTOs\Webhooks\WebhookBasicAuth;
use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Enums\Webhooks\DeliveryOutcome;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Exceptions\Modules\OutboundBlockedException;
use App\Models\WebhookSubscription;
use App\Support\Webhooks\WebhookEgressClient;
use App\Support\Webhooks\WebhookSignature;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeliverWebhookAction
{
    private int $goneStatus = 410;

    public function __construct(
        private readonly WebhookEgressClient $egressClient,
        private readonly WebhookSignature $signature,
        private readonly Encrypter $encrypter,
    ) {}

    /**
     * @throws ConnectionException
     * @throws RuntimeException
     */
    public function execute(WebhookDeliveryData $input): DeliveryOutcome
    {
        $subscription = WebhookSubscription::withoutTenantScope()
            ->where('tenant_id', $input->tenantId)
            ->whereKey($input->subscriptionId)
            ->first();

        if (!$subscription instanceof WebhookSubscription || $subscription->status !== WebhookSubscriptionStatus::Active) {
            return DeliveryOutcome::Skipped;
        }

        $rawBody = $input->unsealedBody($this->encrypter);
        $timestamp = Carbon::now()->getTimestamp();

        $headers = [
            'X-Signature' => $this->signature->sign($rawBody, $subscription->secret, $timestamp),
            'X-Event-Id' => $input->eventId,
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
            Log::warning('Webhook delivery could not reach its target.', [...$this->context($input), 'exception' => $exception::class]);

            throw $exception;
        }

        return $this->classify($input, $response);
    }

    /**
     * @throws RuntimeException
     */
    private function classify(WebhookDeliveryData $input, Response $response): DeliveryOutcome
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return DeliveryOutcome::Delivered;
        }

        if ($status === $this->goneStatus) {
            return DeliveryOutcome::Gone;
        }

        if ($status >= 400 && $status < 500) {
            return DeliveryOutcome::Parked;
        }

        Log::warning('Webhook delivery failed.', [...$this->context($input), 'status' => $status]);

        throw new RuntimeException("Webhook delivery to subscription \"{$input->subscriptionId}\" failed with status {$status}.");
    }

    /**
     * @return array<string, int|string>
     */
    private function context(WebhookDeliveryData $input): array
    {
        return [
            'tenant_id' => $input->tenantId,
            'subscription_id' => $input->subscriptionId,
            'event_id' => $input->eventId,
            'event_type' => $input->eventType,
        ];
    }
}
