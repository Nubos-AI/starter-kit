<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\Salutation;
use App\Enums\Users\UserStatus;
use App\Exceptions\Users\InvitationNotAcceptableException;
use App\Models\User;
use App\Traits\Users\PasswordValidationRules;
use App\Traits\Users\ProfileValidationRules;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AcceptInvitationAction
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvitationNotAcceptableException
     */
    public function execute(User $user, array $data): void
    {
        $validated = Validator::make(
            $data,
            [
                'salutation' => $this->salutationRules(),
                'first_name' => $this->firstNameRules(),
                'last_name' => $this->lastNameRules(),
                'password' => $this->passwordRules(),
            ]
        )->validate();

        $consumed = User::query()
            ->whereKey($user->getKey())
            ->where('status', UserStatus::Invited)
            ->whereNotNull('invitation_token_hash')
            ->where('invitation_expires_at', '>', now())
            ->update([
                'salutation' => $validated['salutation'] ?? Salutation::Unknown->value,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'password' => Hash::make((string) $validated['password']),
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
