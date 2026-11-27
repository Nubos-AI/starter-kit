<?php

declare(strict_types=1);

namespace App\Actions\Sso;

use App\Actions\Authorization\SyncUserRolesAction;
use App\DTOs\Sso\ProvisionedUserData;
use App\Enums\Users\Salutation;
use App\Enums\Users\UserStatus;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionIdentityUserAction
{
    public function __construct(
        private readonly SyncUserRolesAction $syncUserRoles,
        private readonly AdminArtifactAuditor $auditor,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(string $tenantId, ProvisionedUserData $data): User
    {
        return DB::transaction(function () use ($tenantId, $data): User {
            $user = User::query()->make([
                'tenant_id' => $tenantId,
                'status' => $data->isActive ? UserStatus::Accepted : UserStatus::Blocked,
                'salutation' => Salutation::Unknown,
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'email' => $data->email,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            if ($data->roleId !== null) {
                $this->syncUserRoles->execute($user, [$data->roleId]);
            }

            $this->auditor->recordEvent($user, 'identity.provisioned', ['is_active' => $data->isActive], $tenantId);

            Log::info('A user was provisioned by the identity provider.', ['tenant_id' => $tenantId, 'user_id' => $user->getKey()]);

            return $user;
        });
    }
}
