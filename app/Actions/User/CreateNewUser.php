<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\Salutation;
use App\Models\User;
use App\Traits\User\PasswordValidationRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /** @param array<string, mixed> $input */
    public function execute(array $input): User
    {
        $validated = Validator::make($input, [
            'salutation' => ['nullable', Rule::enum(Salutation::class)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ])->validate();

        return User::query()->create(
            [
                'salutation' => $validated['salutation'] ?? null,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ],
        );
    }
}
