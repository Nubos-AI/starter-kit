<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateWebhookSubscriptionAction
{
    public function __construct(
        private readonly ActivateSubscriptionViaChallengeAction $activateSubscription,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(WebhookSubscription $subscription, array $input): WebhookSubscriptionStatus
    {
        $validated = Validator::make(
            $input,
            [
                'name' => ['required', 'string', 'max:255'],
                'target_url' => ['required', 'url', 'max:2048'],
                'auth_username' => ['nullable', 'string', 'max:255'],
                'auth_password' => ['nullable', 'string', 'max:255'],
                'event_types' => ['required', 'array', 'min:1'],
                'event_types.*' => ['required', Rule::in(config('webhooks.events', []))],
                'object_type_id' => ['nullable', 'string', Rule::exists('object_types', 'id')->where('tenant_id', $subscription->tenant_id)],
            ]
        )->validate();

        $targetChanged = $validated['target_url'] !== $subscription->target_url;

        $subscription->update([
            'name' => $validated['name'],
            'target_url' => $validated['target_url'],
            'event_types' => array_values($validated['event_types']),
            'object_type_id' => $validated['object_type_id'] ?? null,
            ...$this->credentials($subscription, $validated),
        ]);

        if (!$targetChanged) {
            return $subscription->status;
        }

        $subscription->update([
            'status' => WebhookSubscriptionStatus::Pending,
            'activated_at' => null,
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);

        return $this->activateSubscription->execute($subscription);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{auth_username: string|null, auth_password: string|null}
     */
    private function credentials(WebhookSubscription $subscription, array $validated): array
    {
        $username = $validated['auth_username'] ?? null;

        if ($username === null || $username === '') {
            return ['auth_username' => null, 'auth_password' => null];
        }

        $password = $validated['auth_password'] ?? null;

        return [
            'auth_username' => $username,
            'auth_password' => $password === null || $password === ''
                ? $subscription->auth_password
                : $password,
        ];
    }
}
