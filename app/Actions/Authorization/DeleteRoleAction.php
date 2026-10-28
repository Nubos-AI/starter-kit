<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleDeletionGuard;
use App\Support\Authorization\SelfLockoutGuard;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeleteRoleAction
{
    public function __construct(
        private readonly SelfLockoutGuard $selfLockoutGuard,
        private readonly RoleDeletionGuard $roleDeletionGuard,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(User $actingUser, Role $role): void
    {
        DB::transaction(function () use ($role, $actingUser): void {
            $this->selfLockoutGuard->assertEscalationSurvivesRoleChange($role);
            $this->roleDeletionGuard->assertRoleHasNoAssignedUsers($role);

            $role->delete();

            $this->selfLockoutGuard->assertActingUserRetainsRoleManagement($actingUser, 'roles.delete');
        });
    }
}
