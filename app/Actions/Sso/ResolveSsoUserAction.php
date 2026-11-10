<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Actions\Users\ConsumeInvitationAction;
use App\Actions\Users\ReleaseUnverifiedRegistrationAction;
use App\DTOs\Sso\IdTokenClaims;
use App\DTOs\Sso\ProvisionedUserData;
use App\Enums\Sso\IdentityProvider;
use App\Enums\Sso\SsoFailure;
use App\Enums\Users\RegistrationReleaseReason;
use App\Enums\Users\UserStatus;
use App\Exceptions\Sso\IdentityLinkedToAnotherUserException;
use App\Exceptions\Sso\SsoLoginException;
use App\Exceptions\Tenancy\TenantPurgeFailedException;
use App\Exceptions\Users\InvitationNotAcceptableException;
use App\Models\Role;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\PermissionSubsetGuard;
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
        private readonly ReleaseUnverifiedRegistrationAction $releaseRegistration,
        private readonly PermissionSubsetGuard $subsetGuard,
        private readonly AuthorizationDirectory $directory,
    ) {}

    /**
     * @throws SsoLoginException
     */
    public function execute(SsoConnection $connection, IdTokenClaims $claims): User
    {
        $tenantId = $connection->tenant_id;
        $linked = $this->users->byIdentity($tenantId, IdentityProvider::Oidc, $claims->issuer, $claims->subject);

        if ($linked instanceof User) {
            return $this->active($this->unprivileged($linked));
        }

        if (!$claims->isEmailVerified || $claims->email === null) {
            throw new SsoLoginException(SsoFailure::EmailNotVerified);
        }

        if (!$connection->claimsEmail($claims->email)) {
            throw new SsoLoginException(SsoFailure::NoAccount);
        }

        $existing = $this->foreignAccountReleased($tenantId, $this->users->byEmail($claims->email));

        if ($existing instanceof User) {
            $this->steerableByConfigurer($connection, $this->unprivileged($existing));
        }

        if ($existing instanceof User && $this->isPendingInvitation($existing)) {
            return $this->acceptInvitation($existing, $claims);
        }

        if ($existing instanceof User) {
            $this->link($this->active($existing), $claims);

            return $existing;
        }

        if (!$connection->is_jit_enabled) {
            throw new SsoLoginException(SsoFailure::NoAccount);
        }

        $this->assertConfigurerMayProvision($connection);

        return DB::transaction(function () use ($connection, $claims, $tenantId): User {
            $user = $this->provisionUser->execute($tenantId, new ProvisionedUserData(
                (string) $claims->email,
                $claims->givenName,
                $claims->familyName,
                true,
                $connection->default_role_id,
            ));

            $this->link($user, $claims);

            return $user;
        });
    }

    /**
     * @throws SsoLoginException
     */
    private function foreignAccountReleased(string $tenantId, ?User $existing): ?User
    {
        if (!$existing instanceof User || $existing->tenant_id === $tenantId) {
            return $existing;
        }

        try {
            $isReleased = $this->releaseRegistration->execute($existing, RegistrationReleaseReason::ClaimedBySingleSignOn);
        } catch (TenantPurgeFailedException $exception) {
            Log::error('A single sign-on could not release an unverified registration of another tenant.', ['tenant_id' => $tenantId, 'user_id' => $existing->getKey()]);

            throw new SsoLoginException(SsoFailure::ForeignAccount, $exception);
        }

        if ($isReleased) {
            return null;
        }

        Log::warning('A single sign-on named an email address of another tenant.', ['tenant_id' => $tenantId]);

        throw new SsoLoginException(SsoFailure::ForeignAccount);
    }

    /**
     * @throws SsoLoginException
     */
    private function link(User $user, IdTokenClaims $claims): void
    {
        try {
            $this->linkIdentity->execute($user, IdentityProvider::Oidc, $claims->issuer, $claims->subject);
        } catch (IdentityLinkedToAnotherUserException $exception) {
            throw new SsoLoginException(SsoFailure::IdentityLinkedElsewhere, $exception);
        }
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
                $this->link($user, $claims);

                return $user;
            });
        } catch (InvitationNotAcceptableException $exception) {
            Log::warning('A single sign-on met an invitation that is no longer pending.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

            throw new SsoLoginException(SsoFailure::AccountInactive, $exception);
        }
    }

    /**
     * @throws SsoLoginException
     */
    private function unprivileged(User $user): User
    {
        if (!$user->isEscalatedAuthority()) {
            return $user;
        }

        Log::warning('A single sign-on named an owner or super admin account.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

        throw new SsoLoginException(SsoFailure::PrivilegedAccount);
    }

    /**
     * @throws SsoLoginException
     */
    private function steerableByConfigurer(SsoConnection $connection, User $user): User
    {
        $configurer = $connection->configurer;

        if ($configurer instanceof User && $this->isEntitledConfigurer($connection, $configurer) && $this->subsetGuard->maySteerAccount($configurer, $user)) {
            return $user;
        }

        Log::warning('A single sign-on named an account the configurer of the connection may not steer.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey(), 'configurer_id' => $connection->configured_by_id]);

        throw new SsoLoginException(SsoFailure::UnsteerableAccount);
    }

    /**
     * @throws SsoLoginException
     */
    private function assertConfigurerMayProvision(SsoConnection $connection): void
    {
        $configurer = $connection->configurer;

        if (!$configurer instanceof User || !$this->isEntitledConfigurer($connection, $configurer)) {
            Log::warning('A single sign-on tried to provision an account while the connection has no entitled configurer.', ['tenant_id' => $connection->tenant_id, 'configurer_id' => $connection->configured_by_id]);

            throw new SsoLoginException(SsoFailure::NoAccount);
        }

        $roleIds = $connection->default_role_id === null ? [] : [$connection->default_role_id];
        $role = $this->directory->rolesByIds($roleIds)->first(fn (Role $role): bool => !$this->subsetGuard->mayAssignRole($configurer, $role));

        if (!$role instanceof Role) {
            return;
        }

        Log::warning('A single sign-on tried to provision an account with a default role beyond the rights of the configurer.', ['tenant_id' => $connection->tenant_id, 'configurer_id' => $configurer->getKey(), 'role_id' => $role->getKey()]);

        throw new SsoLoginException(SsoFailure::NoAccount);
    }

    private function isEntitledConfigurer(SsoConnection $connection, User $configurer): bool
    {
        return $configurer->tenant_id === $connection->tenant_id
            && $configurer->status === UserStatus::Accepted
            && !$configurer->trashed()
            && $configurer->hasPermission('sso.manage');
    }

    private function active(User $user): User
    {
        if ($user->trashed() || !$user->status->canAuthenticate()) {
            throw new SsoLoginException(SsoFailure::AccountInactive);
        }

        return $user;
    }
}
