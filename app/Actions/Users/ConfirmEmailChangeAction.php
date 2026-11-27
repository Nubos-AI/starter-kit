<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\Users\EmailChangeNotConfirmableException;
use App\Models\User;
use App\Support\Sso\SsoEnforcementGate;
use App\Traits\Users\ProfileValidationRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfirmEmailChangeAction
{
    use ProfileValidationRules;

    public function __construct(private readonly SsoEnforcementGate $ssoEnforcement) {}

    /**
     * @throws ValidationException
     */
    public function execute(User $user, string $token): ?string
    {
        try {
            $pendingEmail = $this->openRequestOf($user, $token);
        } catch (EmailChangeNotConfirmableException $exception) {
            Log::info('An email address change was not confirmed because its request closed meanwhile.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey(), 'reason' => $exception->getMessage()]);

            throw ValidationException::withMessages(['email' => __('i18n.backend.actions.users.confirm_email_change_action.the_confirmation_link_is_no_longer_valid')]);
        }

        if ($this->ssoEnforcement->releasesEnforcedEmail($user, $pendingEmail)) {
            Log::info('An email address change was not confirmed because it would leave the enforced single sign-on domains.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

            throw ValidationException::withMessages(['email' => __('i18n.backend.actions.users.update_profile_action.email_must_stay_within_the_sso_domains')]);
        }

        Validator::make(['email' => $pendingEmail], ['email' => $this->emailRules((string) $user->getKey())])->validate();

        $previousEmail = $user->email;

        try {
            $user->forceFill([
                'email' => $pendingEmail,
                'email_verified_at' => now(),
                'pending_email' => null,
                'pending_email_token_hash' => null,
                'pending_email_expires_at' => null,
            ])->save();
        } catch (UniqueConstraintViolationException $exception) {
            Log::info('An email address change was not confirmed because another account took the address meanwhile.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey(), 'exception' => $exception::class]);

            throw ValidationException::withMessages(['email' => __('validation.unique', ['attribute' => __('validation.attributes.email')])]);
        }

        Log::info('A user email address change was confirmed by the new address.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

        return $user->status->canAuthenticate() ? $previousEmail : null;
    }

    /**
     * @throws EmailChangeNotConfirmableException
     */
    public function openRequestOf(User $user, string $token): string
    {
        $pendingEmail = $user->pending_email;
        $tokenHash = $user->pending_email_token_hash;
        $expiresAt = $user->pending_email_expires_at;

        if ($pendingEmail === null || $tokenHash === null || $expiresAt === null || $expiresAt->isPast() || !hash_equals($tokenHash, hash('sha256', $token))) {
            throw EmailChangeNotConfirmableException::noOpenRequest();
        }

        return $pendingEmail;
    }
}
