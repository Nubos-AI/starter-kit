<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateWebhookSubscriptionAction
{
    public function __construct(
        private readonly ActivateSubscriptionViaChallengeAction $activateSubscription,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
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
                'team_id' => ['nullable', 'string', Rule::exists('teams', 'id')->where('tenant_id', $subscription->tenant_id)->whereNull('deleted_at')],
            ]
        )->validate();

        $targetChanged = $validated['target_url'] !== $subscription->target_url;

        $subscription->update([
            'team_id' => $validated['team_id'] ?? null,
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
     *
     * @throws ValidationException
     */
    private function credentials(WebhookSubscription $subscription, array $validated): array
    {
        $username = $validated['auth_username'] ?? null;

        if ($username === null || $username === '') {
            return ['auth_username' => null, 'auth_password' => null];
        }

        $password = $validated['auth_password'] ?? null;

        if ($password !== null && $password !== '') {
            return ['auth_username' => $username, 'auth_password' => $password];
        }

        if ($subscription->auth_password !== null && !$this->isSameOrigin($subscription->target_url, (string) $validated['target_url'])) {
            throw ValidationException::withMessages([
                'auth_password' => __('i18n.backend.actions.webhooks.update_webhook_subscription_action.password_required_for_new_host'),
            ]);
        }

        return ['auth_username' => $username, 'auth_password' => $subscription->auth_password];
    }

    private function isSameOrigin(string $storedUrl, string $newUrl): bool
    {
        return $this->originOf($storedUrl) === $this->originOf($newUrl);
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '';
        }

        $scheme = Str::lower($parts['scheme'] ?? '');
        $host = Str::lower($parts['host'] ?? '');
        $port = $parts['port'] ?? match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => '',
        };

        return "{$scheme}://{$host}:{$port}";
    }
}
