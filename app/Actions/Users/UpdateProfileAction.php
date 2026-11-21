<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Sso\SsoEnforcementGate;
use App\Traits\Users\PasswordValidationRules;
use App\Traits\Users\ProfileValidationRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateProfileAction
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function __construct(
        private readonly UpdateUserAction $updateUser,
        private readonly RequestEmailChangeAction $requestEmailChange,
        private readonly SsoEnforcementGate $ssoEnforcement,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function execute(User $user, array $data): User
    {
        $validated = Validator::make(
            $this->withNormalizedEmail($data),
            [...$this->personalDetailsRules(), 'email' => $this->emailAddressRules()],
        )->validate();

        $email = (string) $validated['email'];

        if ($this->ssoEnforcement->releasesEnforcedEmail($user, $email)) {
            Log::info('A profile email change was refused because it would leave the enforced single sign-on domains.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

            throw ValidationException::withMessages(['email' => __('i18n.backend.actions.users.update_profile_action.email_must_stay_within_the_sso_domains')]);
        }

        $isEmailChanged = $email !== $user->email;

        if ($isEmailChanged) {
            Validator::make($data, ['current_password' => $this->currentPasswordRules()])->validate();
        }

        $updated = $this->updateUser->execute($user, Arr::except($validated, ['email']));

        if ($isEmailChanged) {
            $this->requestEmailChange->execute($updated, $email);
        }

        return $updated;
    }
}
