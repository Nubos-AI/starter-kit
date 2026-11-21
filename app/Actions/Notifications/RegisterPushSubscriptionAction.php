<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Exceptions\Webhooks\SsrfBlockedException;
use App\Models\PushSubscription as PushSubscriptionModel;
use App\Models\User;
use App\Support\Webhooks\SsrfGuard;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Minishlink\WebPush\ContentEncoding;
use NotificationChannels\WebPush\PushSubscription;

class RegisterPushSubscriptionAction
{
    public function __construct(private readonly SsrfGuard $ssrfGuard) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function execute(User $user, array $input): PushSubscription
    {
        $validated = Validator::make(
            $input,
            [
                'endpoint' => ['required', 'string', 'max:2000', 'url:https'],
                'public_key' => ['nullable', 'string', 'required_with:auth_token', $this->decodesToKey(65, "\x04")],
                'auth_token' => ['nullable', 'string', 'required_with:public_key', $this->decodesToKey(16)],
                'content_encoding' => ['nullable', 'string', Rule::enum(ContentEncoding::class)],
            ]
        )->validate();

        $this->assertEndpointAllowed($user, $validated['endpoint']);

        return DB::transaction(function () use ($user, $validated): PushSubscription {
            $this->releaseEndpointFromOtherOwners(
                $user,
                $validated['endpoint'],
                $validated['public_key'] ?? null,
                $validated['auth_token'] ?? null,
            );

            return $user->updatePushSubscription(
                $validated['endpoint'],
                $validated['public_key'] ?? null,
                $validated['auth_token'] ?? null,
                $validated['content_encoding'] ?? null,
            );
        });
    }

    /**
     * @throws ValidationException
     */
    private function releaseEndpointFromOtherOwners(User $user, string $endpoint, ?string $publicKey, ?string $authToken): void
    {
        $foreign = PushSubscriptionModel::withoutTenantScope()
            ->where('endpoint', $endpoint)
            ->whereNot(static fn (Builder $query): Builder => $query
                ->where('subscribable_type', $user->getMorphClass())
                ->where('subscribable_id', $user->getKey()))
            ->get(['id', 'public_key', 'auth_token']);

        if ($foreign->isEmpty()) {
            return;
        }

        $isSameBrowser = $foreign->every(static fn (PushSubscriptionModel $subscription): bool => $publicKey !== null
            && $authToken !== null
            && is_string($subscription->public_key)
            && is_string($subscription->auth_token)
            && hash_equals($subscription->public_key, $publicKey)
            && hash_equals($subscription->auth_token, $authToken));

        if (!$isSameBrowser) {
            Log::warning('A push endpoint registered by another user was not handed over because the browser keys differ.', [
                'user_id' => $user->getKey(),
                'tenant_id' => $user->tenant_id,
                'foreign_subscription_count' => $foreign->count(),
            ]);

            throw ValidationException::withMessages([
                'endpoint' => __('i18n.backend.actions.notifications.register_push_subscription_action.endpoint_owned_elsewhere'),
            ]);
        }

        $released = PushSubscriptionModel::withoutTenantScope()
            ->whereKey($foreign->modelKeys())
            ->delete();

        Log::info('A push endpoint moved to another user.', ['user_id' => $user->getKey(), 'tenant_id' => $user->tenant_id, 'released' => $released]);
    }

    private function decodesToKey(int $length, string $prefix = ''): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($length, $prefix): void {
            $decoded = is_string($value) ? base64_decode(strtr($value, '-_', '+/'), true) : false;

            if ($decoded === false || strlen($decoded) !== $length || !str_starts_with($decoded, $prefix)) {
                $fail(__('i18n.backend.actions.notifications.register_push_subscription_action.invalid_key'));
            }
        };
    }

    /**
     * @throws ValidationException
     */
    private function assertEndpointAllowed(User $user, string $endpoint): void
    {
        try {
            $this->ssrfGuard->assertWebhookTargetAllowed($endpoint);
        } catch (SsrfBlockedException $exception) {
            Log::warning('Push subscription endpoint blocked by the SSRF guard', [
                'user_id' => $user->getKey(),
                'tenant_id' => $user->tenant_id,
                'blocked_ip' => $exception->ip,
            ]);

            throw ValidationException::withMessages([
                'endpoint' => __('i18n.backend.actions.notifications.register_push_subscription_action.endpoint_blocked'),
            ]);
        }
    }
}
