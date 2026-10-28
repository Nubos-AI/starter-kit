<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\UserStatus;
use App\Models\User;
use App\Traits\Users\PasswordValidationRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPasswordAction implements ResetsUserPasswords
{
    use PasswordValidationRules;

    private string $unreachableReason = 'i18n.backend.actions.users.reset_user_password_action.the_password_for_this_account_cannot_be_reset_contact';

    /**
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        if ($user->status !== UserStatus::Accepted) {
            throw ValidationException::withMessages(['email' => __($this->unreachableReason)]);
        }

        Validator::make(
            $input,
            [
                'password' => $this->passwordRules(),
            ]
        )->validate();

        $user->forceFill(
            [
                'password' => $input['password'],
            ]
        )->save();
    }
}
