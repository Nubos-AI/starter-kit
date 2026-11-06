<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Notifications\Users\UserPasswordChangedNotification;
use App\Support\Users\UserSessionInvalidator;
use App\Traits\Users\PasswordValidationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SetUserPasswordAction
{
    use PasswordValidationRules;

    public function __construct(private readonly UserSessionInvalidator $sessionInvalidator) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public function execute(User $user, array $data): void
    {
        $validated = Validator::make(
            $data,
            [
                'password' => $this->passwordRules(),
            ]
        )->validate();

        DB::transaction(function () use ($user, $validated): void {
            $user->forceFill(['password' => $validated['password']])->save();

            $this->sessionInvalidator->invalidate($user);
        });

        $user->notify(new UserPasswordChangedNotification);
    }
}
