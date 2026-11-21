<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Actions\Authorization\AssignRoleAction;
use App\Actions\Authorization\SeedGlobalPermissionsAction;
use App\Actions\Authorization\SeedTenantRolesAction;
use App\Actions\Teams\CreateTeamAction;
use App\DTOs\Tenancy\TenantRegistrationData;
use App\Enums\Users\UserStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Modules\TenantProvisioningExtensions;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProvisionTenantAction
{
    public function __construct(
        private readonly TenantBinder $tenants,
        private readonly SeedGlobalPermissionsAction $seedGlobalPermissions,
        private readonly SeedTenantRolesAction $seedTenantRoles,
        private readonly CreateTeamAction $createTeam,
        private readonly AssignRoleAction $assignRole,
        private readonly TenantProvisioningExtensions $extensions,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(TenantRegistrationData $data): User
    {
        return DB::transaction(function () use ($data): User {
            $tenant = Tenant::query()->create([
                'name' => $data->companyName,
                'slug' => Str::lower((string) Str::ulid()),
            ]);

            return $this->tenants->runWith($tenant, fn (Tenant $tenant): User => $this->furnish($tenant, $data));
        });
    }

    /**
     * @throws Throwable
     */
    private function furnish(Tenant $tenant, TenantRegistrationData $data): User
    {
        $this->seedGlobalPermissions->execute();

        $ownerRole = $this->seedTenantRoles->execute();

        $owner = User::query()->create([
            'tenant_id' => $tenant->getKey(),
            'status' => UserStatus::Accepted,
            'salutation' => $data->salutation,
            'first_name' => $data->firstName,
            'last_name' => $data->lastName,
            'email' => $data->email,
            'password' => $data->password,
        ]);

        $team = $this->createTeam->execute($owner, [
            'name' => $data->companyName,
            'owner_id' => (string) $owner->getKey(),
        ]);

        $team->users()->syncWithoutDetaching([(string) $owner->getKey()]);

        $owner->update(['current_team_id' => (string) $team->getKey()]);

        $this->assignRole->assign($owner, $ownerRole, $tenant);

        $this->extensions->provisioned($tenant);

        return $owner;
    }
}
