<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Enums\Users\UserStatus;
use App\Models\User;

class PendingInvitationResolver
{
    public function resolveOrFail(string $token): User
    {
        $user = User::query()
            ->where('invitation_token_hash', hash('sha256', $token))
            ->where('status', UserStatus::Invited)
            ->first();

        $expiresAt = $user?->invitation_expires_at;

        if (!$user instanceof User || $expiresAt === null || $expiresAt->isPast()) {
            abort(404);
        }

        return $user;
    }
}
