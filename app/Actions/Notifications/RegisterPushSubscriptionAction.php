<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use NotificationChannels\WebPush\PushSubscription;

class RegisterPushSubscriptionAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $user, array $input): PushSubscription
    {
        $validated = Validator::make(
            $input,
            [
                'endpoint' => ['required', 'string'],
                'public_key' => ['nullable', 'string'],
                'auth_token' => ['nullable', 'string'],
                'content_encoding' => ['nullable', 'string'],
            ]
        )->validate();

        return $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['public_key'] ?? null,
            $validated['auth_token'] ?? null,
            $validated['content_encoding'] ?? null,
        );
    }
}
