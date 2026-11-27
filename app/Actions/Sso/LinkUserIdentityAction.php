<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Enums\Sso\IdentityProvider;
use App\Exceptions\Sso\IdentityLinkedToAnotherUserException;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\Log;

class LinkUserIdentityAction
{
    /**
     * @throws IdentityLinkedToAnotherUserException
     */
    public function execute(User $user, IdentityProvider $provider, string $issuer, string $subject): UserIdentity
    {
        $identity = UserIdentity::query()->firstOrCreate(
            [
                'tenant_id' => $user->tenant_id,
                'provider' => $provider->value,
                'issuer' => $issuer,
                'subject' => $subject,
            ],
            ['user_id' => $user->getKey()],
        );

        if ($identity->user_id !== (string) $user->getKey()) {
            Log::warning('An external identity is already linked to another user.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey(), 'provider' => $provider->value]);

            throw new IdentityLinkedToAnotherUserException($provider);
        }

        if ($identity->wasRecentlyCreated) {
            Log::info('An external identity was linked to a user.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey(), 'provider' => $provider->value]);
        }

        return $identity;
    }
}
