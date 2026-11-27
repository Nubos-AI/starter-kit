<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Traits\Users\PasswordValidationRules;
use Illuminate\Support\Facades\Validator;

class DeleteOwnAccountAction
{
    use PasswordValidationRules;

    public function __construct(private readonly DeleteUserAction $deleteUser) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): void
    {
        Validator::make($data, [
            'password' => $this->currentPasswordRules(),
        ])->validate();

        $this->deleteUser->execute($user);
    }
}
