<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Traits\Users\PasswordValidationRules;
use Illuminate\Support\Facades\Validator;

class UpdatePasswordAction
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): void
    {
        $validated = Validator::make(
            $data,
            [
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]
        )->validate();

        $user->update(
            [
                'password' => $validated['password'],
            ]
        );
    }
}
