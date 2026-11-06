<?php

declare(strict_types=1);

namespace App\Actions\Scim;

use App\Actions\Authorization\SyncUserRolesAction;
use App\Actions\Sso\LinkUserIdentityAction;
use App\Actions\Sso\ProvisionIdentityUserAction;
use App\Actions\Users\RestoreUserAction;
use App\DTOs\Scim\ScimUserChanges;
use App\DTOs\Sso\ProvisionedUserData;
use App\Enums\Sso\IdentityProvider;
use App\Enums\Users\UserStatus;
use App\Exceptions\Scim\ScimException;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
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
        private readonly LinkUserIdentityAction $linkIdentity,
        private readonly RestoreUserAction $restoreUser,
        private readonly SyncUserRolesAction $syncUserRoles,
        private readonly AdminArtifactAuditor $auditor,
    ) {}

    /**
     * @throws ScimException
     * @throws Throwable
     */
    public function execute(string $tenantId, ScimUserChanges $changes): User
    {
        $email = Str::lower(trim((string) ($changes->values['email'] ?? '')));

        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing instanceof User && !$this->isRestorable($existing, $tenantId)) {
            throw ScimException::uniqueness();
        }

        $roleId = $this->connections->forTenant($tenantId)?->default_role_id;

        if ($existing instanceof User) {
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

    private function linkExternalId(User $user, ScimUserChanges $changes): void
    {
        $externalId = $changes->values['external_id'] ?? null;

        if ($externalId !== null) {
            $this->linkIdentity->execute($user, IdentityProvider::Scim, self::$issuer, $externalId);
        }
    }
}
