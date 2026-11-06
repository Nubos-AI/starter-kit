<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Authorization\RoleInputRules;
use App\Support\Authorization\SelfLockoutGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SyncRolePermissionsAction
{
    public function __construct(
        private readonly SelfLockoutGuard $guard,
        private readonly AdminArtifactAuditor $auditor,
        private readonly PermissionSubsetGuard $subsetGuard,
        private readonly RoleInputRules $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public function execute(Role $role, array $data): void
    {
        $validated = Validator::make($data, $this->rules->permissionIds('present'))->validate();

        /** @var list<string> $permissionIds */
        $permissionIds = array_values($validated['permission_ids']);

        $actor = Auth::user();
        $actorIsEscalated = $actor instanceof User && $actor->isEscalatedAuthority();

        if ($role->is_system && !$actorIsEscalated) {
            throw new AuthorizationException(__('i18n.backend.actions.authorization.sync_role_permissions_action.system_roles_cannot_be_changed_or_deleted'));
        }

        $this->subsetGuard->assertMayGrantPermissions($role, $permissionIds);

        DB::transaction(function () use ($role, $permissionIds): void {
            $before = $role->permissions()->pluck('permissions.id')->all();
            sort($before);

            $role->permissions()->sync($permissionIds);

            $after = $role->permissions()->pluck('permissions.id')->all();
            sort($after);

            if ($before !== $after) {
                $this->auditor->record(
                    $role,
                    ['permission_ids' => $before],
                    ['permission_ids' => $after],
                );
            }

            $actingUser = Auth::user();

            if ($actingUser instanceof User) {
                $this->guard->assertActingUserRetainsRoleManagement($actingUser);
            }
        });
    }
}
