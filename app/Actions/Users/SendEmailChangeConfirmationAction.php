<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Notifications\Users\ConfirmEmailChangeNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendEmailChangeConfirmationAction
{
    public function execute(User $user, string $token, CarbonInterface $expiresAt, string $deliveryKey): void
    {
        $pendingEmail = $user->pending_email;
        $tokenHash = $user->pending_email_token_hash;

        if ($pendingEmail === null || $tokenHash === null || !hash_equals($tokenHash, hash('sha256', $token))) {
            Log::info('An email change confirmation was not sent because the request was replaced or closed.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

            return;
        }

        $notification = new ConfirmEmailChangeNotification($token, $expiresAt);
        $notification->id = $deliveryKey;

        Notification::route('mail', $pendingEmail)->notify($notification);

        Log::info('The new email address was asked to confirm the change.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);
    }
}
