<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\UserStatus;
use App\Exceptions\Users\InvitationNotAcceptableException;
use App\Models\User;

class ConsumeInvitationAction
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvitationNotAcceptableException
     */
    public function execute(User $user, array $attributes): void
    {
        $consumed = User::query()
            ->whereKey($user->getKey())
            ->where('status', UserStatus::Invited)
            ->whereNotNull('invitation_token_hash')
            ->where('invitation_expires_at', '>', now())
            ->update([
                ...$attributes,
                'status' => UserStatus::Accepted,
                'email_verified_at' => now(),
                'invitation_accepted_at' => now(),
                'invitation_token_hash' => null,
            ]);

        if ($consumed === 0) {
            throw InvitationNotAcceptableException::noLongerPending();
        }

        $user->refresh();
    }
}
