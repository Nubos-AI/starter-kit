<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class SendUserPasswordResetLinkAction
{
    private string $unreachableReason = 'i18n.backend.actions.users.send_user_password_reset_link_action.reset_links_can_only_be_sent_to_users_who';

    public function execute(User $user): void
    {
        if ($user->status !== UserStatus::Accepted) {
            throw ValidationException::withMessages(['password' => __($this->unreachableReason)]);
        }

        Password::sendResetLink(['email' => $user->email]);
    }
}
