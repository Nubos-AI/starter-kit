<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Notifications\Users\UserInvitationNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class SendUserInvitationAction
{
    public function execute(User $user, string $inviterName, string $token, CarbonInterface $expiresAt, string $deliveryKey): void
    {
        $tokenHash = $user->invitation_token_hash;

        if ($tokenHash === null || !hash_equals($tokenHash, hash('sha256', $token))) {
            Log::info('An invitation mail was not sent because a newer invitation replaced its link.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

            return;
        }

        $notification = new UserInvitationNotification($token, $inviterName, $expiresAt);
        $notification->id = $deliveryKey;

        $user->notify($notification);
    }
}
