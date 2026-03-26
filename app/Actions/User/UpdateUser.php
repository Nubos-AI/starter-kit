<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\Salutation;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateUser
{
    /**
     * @param array<string, mixed> $input
     */
    public function execute(User $user, array $input): void
    {
        $validated = Validator::make(
            $input,
            [
                'salutation' => ['nullable', Rule::enum(Salutation::class)],
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            ],
        )->validate();

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
    }
}
