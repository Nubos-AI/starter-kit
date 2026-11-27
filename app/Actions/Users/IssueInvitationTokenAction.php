<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\DTOs\Users\IssuedUserTokenData;
use App\Enums\Users\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IssueInvitationTokenAction
{
    public function execute(User $user): ?IssuedUserTokenData
    {
        if ($user->status !== UserStatus::Invited || $user->trashed()) {
            Log::info('An invitation was not issued because the account is no longer awaiting an invitation.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

            return null;
        }

        $token = Str::random(64);
        $expiresAt = now()->addDays((int) config('users.invitation.ttl_days'));

        $user->forceFill([
            'invitation_token_hash' => hash('sha256', $token),
            'invitation_sent_at' => now(),
            'invitation_expires_at' => $expiresAt,
            'invitation_accepted_at' => null,
        ])->save();

        return new IssuedUserTokenData($token, $expiresAt);
    }
}
