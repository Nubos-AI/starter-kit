<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Enums\Authorization\RoleAuthority;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleAuthorityGuard;
use App\Support\Authorization\RoleInputRules;
use App\Support\Authorization\SelfLockoutGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UpdateRoleAction
{
    public function __construct(
        private readonly SyncRolePermissionsAction $syncRolePermissions,
        private readonly SyncFieldPermissionsAction $syncFieldPermissions,
        private readonly SelfLockoutGuard $selfLockoutGuard,
        private readonly RoleAuthorityGuard $authorityGuard,
        private readonly RoleInputRules $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(User $actingUser, Role $role, array $input): Role
    {
        $validated = Validator::make($input, $this->rules->all($actingUser))->validate();

        $this->authorityGuard->assertMayChange($actingUser, $validated, $role->authority);
        $this->authorityGuard->assertMayChangeSubteamVisibility($actingUser, $validated, $role->grants_subteam_visibility);

        DB::transaction(function () use ($validated, $role, $actingUser): void {
            $attributes = [
                'name' => $validated['name'],
                'scope' => $validated['scope'],
            ];

            if (array_key_exists('authority', $validated)) {
                $requested = $validated['authority'] === null
                    ? null
                    : RoleAuthority::from((string) $validated['authority']);

                if ($requested !== $role->authority) {
                    $this->selfLockoutGuard->assertEscalationSurvivesRoleChange($role);
                }

                $attributes['authority'] = $requested;
            }

            if (array_key_exists('is_system', $validated) && $actingUser->isEscalatedAuthority()) {
                $attributes['is_system'] = (bool) $validated['is_system'];
            }

            if (array_key_exists('grants_subteam_visibility', $validated)) {
                $attributes['grants_subteam_visibility'] = (bool) $validated['grants_subteam_visibility'];
            }

            $role->update($attributes);

            $this->selfLockoutGuard->assertActingUserRetainsRoleManagement($actingUser);
        });

        if (array_key_exists('permission_ids', $validated)) {
            $this->syncRolePermissions->execute($role, ['permission_ids' => array_values($validated['permission_ids'])]);
        }

        if (array_key_exists('field_permissions', $validated)) {
            $this->syncFieldPermissions->execute($role, array_values($validated['field_permissions']));
        }

        return $role;
    }
}
