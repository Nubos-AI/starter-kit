<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Users\UserSessionInvalidator;
use App\Traits\Users\PasswordValidationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UpdatePasswordAction
{
    use PasswordValidationRules;

    public function __construct(private readonly UserSessionInvalidator $sessionInvalidator) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public function execute(User $user, array $data, ?string $currentSessionId = null): void
    {
        $validated = Validator::make(
            $data,
            [
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]
        )->validate();

        DB::transaction(function () use ($user, $validated, $currentSessionId): void {
            $user->forceFill(['password' => $validated['password']])->save();

            $this->sessionInvalidator->invalidate($user, $currentSessionId);
        });
    }
}
