<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\Users\EmailChangeNotConfirmableException;
use App\Models\User;
use App\Notifications\Users\EmailAddressChangedNotification;
use App\Support\Sso\SsoEnforcementGate;
use App\Traits\Users\ProfileValidationRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfirmEmailChangeAction
{
    use ProfileValidationRules;

    public function __construct(private readonly SsoEnforcementGate $ssoEnforcement) {}

    /**
     * @throws EmailChangeNotConfirmableException
     * @throws ValidationException
     */
    public function execute(User $user, string $token): void
    {
        $pendingEmail = $this->openRequestOf($user, $token);

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

        if ($user->status->canAuthenticate()) {
            Notification::route('mail', $previousEmail)->notify(new EmailAddressChangedNotification);
        }

        Log::info('A user email address change was confirmed by the new address and the old one was notified.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);
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
