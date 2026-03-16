<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use App\Traits\User\PasswordValidationRules;
use Illuminate\Support\Facades\Validator;

class UpdatePassword
{
    use PasswordValidationRules;

    public function execute(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user->update([
            'password' => $validated['password'],
        ]);
    }
}
