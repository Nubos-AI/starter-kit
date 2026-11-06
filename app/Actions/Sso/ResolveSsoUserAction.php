<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Actions\Users\ConsumeInvitationAction;
use App\DTOs\Sso\IdTokenClaims;
use App\DTOs\Sso\ProvisionedUserData;
use App\Enums\Sso\IdentityProvider;
use App\Enums\Sso\SsoFailure;
use App\Enums\Users\UserStatus;
use App\Exceptions\Sso\SsoLoginException;
use App\Exceptions\Users\InvitationNotAcceptableException;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\Sso\SsoUserDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResolveSsoUserAction
{
    public function __construct(
        private readonly SsoUserDirectory $users,
        private readonly ProvisionIdentityUserAction $provisionUser,
        private readonly LinkUserIdentityAction $linkIdentity,
        private readonly ConsumeInvitationAction $consumeInvitation,
    ) {}

    /**
     * @throws SsoLoginException
     */
    public function execute(SsoConnection $connection, IdTokenClaims $claims): User
    {
        $tenantId = $connection->tenant_id;
        $linked = $this->users->byIdentity($tenantId, IdentityProvider::Oidc, $claims->issuer, $claims->subject);

        if ($linked instanceof User) {
            return $this->active($linked);
        }

        if (!$claims->isEmailVerified || $claims->email === null) {
            throw new SsoLoginException(SsoFailure::EmailNotVerified);
        }

        if (!$connection->claimsEmail($claims->email)) {
            throw new SsoLoginException(SsoFailure::NoAccount);
        }

        $existing = $this->users->byEmail($claims->email);

        if ($existing instanceof User && $existing->tenant_id !== $tenantId) {
            Log::warning('A single sign-on named an email address of another tenant.', ['tenant_id' => $tenantId]);

            throw new SsoLoginException(SsoFailure::ForeignAccount);
        }

        if ($existing instanceof User && $this->isPendingInvitation($existing)) {
            return $this->acceptInvitation($existing, $claims);
        }

        if ($existing instanceof User) {
            $this->linkIdentity->execute($this->active($existing), IdentityProvider::Oidc, $claims->issuer, $claims->subject);

            return $existing;
        }

        if (!$connection->is_jit_enabled) {
            throw new SsoLoginException(SsoFailure::NoAccount);
        }

        return DB::transaction(function () use ($connection, $claims, $tenantId): User {
            $user = $this->provisionUser->execute($tenantId, new ProvisionedUserData(
                (string) $claims->email,
                $claims->givenName,
                $claims->familyName,
                true,
                $connection->default_role_id,
            ));

            $this->linkIdentity->execute($user, IdentityProvider::Oidc, $claims->issuer, $claims->subject);

            return $user;
        });
    }

    private function isPendingInvitation(User $user): bool
    {
        return !$user->trashed() && $user->status === UserStatus::Invited;
    }

    /**
     * @throws SsoLoginException
     */
    private function acceptInvitation(User $user, IdTokenClaims $claims): User
    {
        try {
            return DB::transaction(function () use ($user, $claims): User {
                $this->consumeInvitation->execute($user, array_filter(
                    ['first_name' => $claims->givenName, 'last_name' => $claims->familyName],
                    static fn (?string $name): bool => $name !== null && $name !== '',
                ));
                $this->linkIdentity->execute($user, IdentityProvider::Oidc, $claims->issuer, $claims->subject);

                return $user;
            });
        } catch (InvitationNotAcceptableException $exception) {
            Log::warning('A single sign-on met an invitation that is no longer pending.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

            throw new SsoLoginException(SsoFailure::AccountInactive, $exception);
        }
    }

    private function active(User $user): User
    {
        if ($user->trashed() || !$user->status->canAuthenticate()) {
            throw new SsoLoginException(SsoFailure::AccountInactive);
        }

        return $user;
    }
}
