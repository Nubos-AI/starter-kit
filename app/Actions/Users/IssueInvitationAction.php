<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Notifications\Users\UserInvitationNotification;
use Illuminate\Support\Str;

class IssueInvitationAction
{
    public function execute(User $user, User $invitedBy): void
    {
        $token = Str::random(64);
        $expiresAt = now()->addDays((int) config('users.invitation.ttl_days'));

        $user->forceFill([
            'invitation_token_hash' => hash('sha256', $token),
            'invitation_sent_at' => now(),
            'invitation_expires_at' => $expiresAt,
            'invitation_accepted_at' => null,
        ])->save();

        $user->notify(new UserInvitationNotification(
            $token,
            $invitedBy->name === '' ? $invitedBy->email : $invitedBy->name,
            $expiresAt,
        ));
    }
}
