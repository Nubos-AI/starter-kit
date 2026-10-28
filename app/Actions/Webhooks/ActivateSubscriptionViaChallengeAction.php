<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\DTOs\Webhooks\WebhookBasicAuth;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Exceptions\Modules\OutboundBlockedException;
use App\Exceptions\Webhooks\SsrfBlockedException;
use App\Models\WebhookSubscription;
use App\Support\Webhooks\WebhookEgressClient;
use App\Support\Webhooks\WebhookSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ActivateSubscriptionViaChallengeAction
{
    public function __construct(
        private readonly WebhookEgressClient $egressClient,
        private readonly WebhookSignature $signature,
    ) {}

    public function execute(WebhookSubscription $subscription): WebhookSubscriptionStatus
    {
        $challenge = Str::random(64);
        $rawBody = json_encode(['challenge' => $challenge], JSON_THROW_ON_ERROR);
        $timestamp = Carbon::now()->getTimestamp();

        $headers = [
            'X-Signature' => $this->signature->sign($rawBody, $subscription->secret, $timestamp),
        ];

        try {
            $response = $this->egressClient->post(
                $subscription->target_url,
                $rawBody,
                $headers,
                WebhookBasicAuth::forSubscription($subscription),
            );
        } catch (OutboundBlockedException $exception) {
            return $this->markPending($subscription, $exception->getMessage());
        } catch (SsrfBlockedException $exception) {
            return $this->markPending($subscription, __('i18n.backend.actions.webhooks.activate_subscription_via_challenge_action.target_blocked').$exception->reason);
        } catch (ConnectionException) {
            return $this->markPending($subscription, __('i18n.backend.actions.webhooks.activate_subscription_via_challenge_action.challenge_ping_could_not_reach_the_target'));
        }

        $status = $response->status();

        if ($status < 200 || $status >= 300) {
            return $this->markPending($subscription, "challenge ping failed with status {$status}");
        }

        $echo = $response->json('challenge');

        if (!is_string($echo) || !hash_equals($challenge, $echo)) {
            return $this->markPending($subscription, __('i18n.backend.actions.webhooks.activate_subscription_via_challenge_action.challenge_echo_did_not_match'));
        }

        $subscription->update([
            'status' => WebhookSubscriptionStatus::Active,
            'activated_at' => Carbon::now(),
            'last_error' => null,
        ]);

        return WebhookSubscriptionStatus::Active;
    }

    private function markPending(WebhookSubscription $subscription, string $reason): WebhookSubscriptionStatus
    {
        $subscription->update([
            'status' => WebhookSubscriptionStatus::Pending,
            'last_error' => $reason,
        ]);

        return WebhookSubscriptionStatus::Pending;
    }
}
