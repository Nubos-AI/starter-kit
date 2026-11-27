<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\DTOs\Users\IssuedUserTokenData;
use App\Models\User;
use App\Traits\Users\ProfileValidationRules;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestEmailChangeAction
{
    use ProfileValidationRules;

    /**
     * @throws ValidationException
     */
    public function execute(User $user, string $email): IssuedUserTokenData
    {
        $validated = Validator::make(
            $this->withNormalizedEmail(['email' => $email]),
            ['email' => $this->emailAddressRules()],
        )->validate();

        $token = Str::random(64);
        $expiresAt = now()->addHours((int) config('users.email_change.ttl_hours'));

        $user->forceFill([
            'pending_email' => (string) $validated['email'],
            'pending_email_token_hash' => hash('sha256', $token),
            'pending_email_expires_at' => $expiresAt,
        ])->save();

        Log::info('An email address change was requested.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

        return new IssuedUserTokenData($token, $expiresAt);
    }
}
