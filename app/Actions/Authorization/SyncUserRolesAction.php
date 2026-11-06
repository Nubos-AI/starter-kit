<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\AssignmentScopeResolver;
use App\Support\Authorization\SelfLockoutGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncUserRolesAction
{
    public function __construct(
        private readonly AssignRoleAction $assignRole,
        private readonly AssignmentScopeResolver $scopeResolver,
        private readonly SelfLockoutGuard $guard,
    ) {}

    /**
     * @param  list<string>  $roleIds
     *
     * @throws Throwable
     */
    public function execute(User $target, array $roleIds): void
    {
        DB::transaction(function () use ($target, $roleIds): void {
            $current = $target->roleAssignments()->pluck('role_id')->all();

            $added = $this->roles(array_values(array_diff($roleIds, $current)));
            $removed = $this->roles(array_values(array_diff($current, $roleIds)));

            foreach ($added as $role) {
                $this->assignRole->assign($target, $role, $this->scopeResolver->resolve($role));
            }

            foreach ($removed as $role) {
                $this->assignRole->remove($target, $role, $this->scopeResolver->resolve($role), checkActingUser: false);
            }

            $actingUser = Auth::user();

            if ($actingUser instanceof User && $actingUser->is($target) && ($added->isNotEmpty() || $removed->isNotEmpty())) {
                $this->guard->assertActingUserRetainsRoleManagement($actingUser);
            }
        });
    }

    /**
     * @param  list<string>  $roleIds
     * @return Collection<int, Role>
     */
    private function roles(array $roleIds): Collection
    {
        if ($roleIds === []) {
            return collect();
        }

        return Role::query()->whereKey($roleIds)->get();
    }
}
