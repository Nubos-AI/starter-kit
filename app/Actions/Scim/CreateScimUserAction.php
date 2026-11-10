<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Authorization\SyncUserRolesAction;
use App\Actions\Sso\ProvisionIdentityUserAction;
use App\Actions\Users\ReleaseUnverifiedRegistrationAction;
use App\Actions\Users\RestoreUserAction;
use App\DTOs\Scim\ScimUserChanges;
use App\DTOs\Sso\ProvisionedUserData;
use App\Enums\Users\RegistrationReleaseReason;
use App\Enums\Users\UserStatus;
use App\Exceptions\Scim\ScimException;
use App\Models\ScimToken;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Scim\ScimAccessGuard;
use App\Support\Sso\SsoConnectionLookup;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CreateScimUserAction
{
    public static string $issuer = 'scim';

    public function __construct(
        private readonly SsoConnectionLookup $connections,
        private readonly ProvisionIdentityUserAction $provisionUser,
        private readonly LinkScimIdentityAction $linkIdentity,
        private readonly RestoreUserAction $restoreUser,
        private readonly ReleaseUnverifiedRegistrationAction $releaseRegistration,
        private readonly SyncUserRolesAction $syncUserRoles,
        private readonly AdminArtifactAuditor $auditor,
        private readonly ScimAccessGuard $accessGuard,
    ) {}

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function execute(ScimToken $token, ScimUserChanges $changes): User
    {
        $tenantId = $token->tenant_id;
        $email = Str::lower(trim((string) ($changes->values['email'] ?? '')));
        $connection = $this->connections->forTenant($tenantId);

        $this->accessGuard->assertMayProvision($token, $connection, $email);

        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing instanceof User && !$this->isRestorable($existing, $tenantId)) {
            if (!$this->releaseRegistration->execute($existing, RegistrationReleaseReason::ClaimedByScim)) {
                throw ScimException::uniqueness();
            }

            $existing = null;
        }

        $roleId = $connection?->default_role_id;

        if ($existing instanceof User) {
            $this->accessGuard->assertMaySteer($token, $existing);

            return $this->restore($existing, $changes, $roleId);
        }

        return DB::transaction(function () use ($tenantId, $changes, $email, $roleId): User {
            $user = $this->provisionUser->execute($tenantId, new ProvisionedUserData(
                $email,
                $changes->values['first_name'] ?? null,
                $changes->values['last_name'] ?? null,
                $changes->values['is_active'] ?? true,
                $roleId,
            ));

            $this->linkExternalId($user, $changes);

            return $user;
        });
    }

    private function isRestorable(User $existing, string $tenantId): bool
    {
        return $existing->trashed() && $existing->tenant_id === $tenantId && !$existing->is_service;
    }

    /**
     * @throws Throwable
     */
    private function restore(User $user, ScimUserChanges $changes, ?string $roleId): User
    {
        return DB::transaction(function () use ($user, $changes, $roleId): User {
            $isActive = $changes->values['is_active'] ?? true;

            $this->restoreUser->execute($user, $isActive ? UserStatus::Accepted : UserStatus::Blocked);

            $user->fill(Arr::only($changes->values, ['first_name', 'last_name']));
            $user->forceFill(['email_verified_at' => now()])->save();

            if ($roleId !== null) {
                $this->syncUserRoles->execute($user, [$roleId]);
            }

            $this->linkExternalId($user, $changes);
            $this->auditor->recordEvent($user, 'scim.user_restored', ['is_active' => $isActive], $user->tenant_id);

            Log::info('A removed user was restored through scim.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);

            return $user;
        });
    }

    /**
     * @throws ScimException
     */
    private function linkExternalId(User $user, ScimUserChanges $changes): void
    {
        $externalId = $changes->values['external_id'] ?? null;

        if ($externalId === null) {
            return;
        }

        $this->linkIdentity->execute($user, $externalId);
    }
}
