<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Sso\SsoFailure;
use App\Enums\Users\UserStatus;
use App\Models\User;
use App\Support\Sso\SsoEnforcementGate;
use App\Support\Users\UserSessionInvalidator;
use App\Traits\Users\PasswordValidationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Throwable;

class ResetUserPasswordAction implements ResetsUserPasswords
{
    use PasswordValidationRules;

    private string $unreachableReason = 'i18n.backend.actions.users.reset_user_password_action.the_password_for_this_account_cannot_be_reset_contact';

    public function __construct(
        private readonly SsoEnforcementGate $ssoEnforcement,
        private readonly UserSessionInvalidator $sessionInvalidator,
    ) {}

    /**
     * @param  array<string, string>  $input
     *
     * @throws Throwable
     */
    public function reset(User $user, array $input): void
    {
        if ($user->status !== UserStatus::Accepted) {
            throw ValidationException::withMessages(['email' => __($this->unreachableReason)]);
        }

        if ($this->ssoEnforcement->blocksLocalLogin($user)) {
            Log::info('A password reset was refused because single sign-on is enforced for the user.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

            throw ValidationException::withMessages(['email' => SsoFailure::LocalLoginEnforced->label()]);
        }

        $validated = Validator::make(
            $input,
            [
                'password' => $this->passwordRules(),
            ]
        )->validate();

        DB::transaction(function () use ($user, $validated): void {
            $user->forceFill(['password' => $validated['password']])->save();

            $this->sessionInvalidator->invalidate($user);
        });
    }
}
