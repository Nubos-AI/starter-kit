<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Validator;

class UnregisterPushSubscriptionAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $user, array $input): void
    {
        $validated = Validator::make(
            $input,
            ['endpoint' => ['required', 'string']]
        )->validate();

        $user->deletePushSubscription($validated['endpoint']);
    }
}
