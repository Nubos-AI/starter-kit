<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\Salutation;
use App\Exceptions\Users\InvitationNotAcceptableException;
use App\Models\User;
use App\Support\Sso\SsoEnforcementGate;
use App\Traits\Users\PasswordValidationRules;
use App\Traits\Users\ProfileValidationRules;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AcceptInvitationAction
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function __construct(
        private readonly ConsumeInvitationAction $consumeInvitation,
        private readonly SsoEnforcementGate $ssoEnforcement,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvitationNotAcceptableException
     * @throws ValidationException
     */
    public function execute(User $user, array $data): void
    {
        if ($this->ssoEnforcement->blocksLocalLogin($user)) {
            Log::info('An invitation acceptance with a password was refused because single sign-on is enforced for the invitee.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

            throw ValidationException::withMessages(['email' => __('i18n.backend.actions.users.accept_invitation_action.sign_in_with_sso_to_accept')]);
        }

        $validated = Validator::make(
            $data,
            [
                'salutation' => $this->salutationRules(),
                'first_name' => $this->firstNameRules(),
                'last_name' => $this->lastNameRules(),
                'password' => $this->passwordRules(),
            ]
        )->validate();

        $this->consumeInvitation->execute($user, [
            'salutation' => $validated['salutation'] ?? Salutation::Unknown->value,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'password' => Hash::make((string) $validated['password']),
        ]);
    }
}
