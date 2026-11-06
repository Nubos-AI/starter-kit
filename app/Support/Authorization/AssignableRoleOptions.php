<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class AssignableRoleOptions
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(Request $request, User $actingUser, bool $withPermissions = false): array
    {
        $roles = Role::query()->orderBy('name');

        if ($withPermissions) {
            $roles->with('permissions');
        }

        return $roles->get()->map(function (Role $role) use ($request, $actingUser, $withPermissions): array {
            $payload = RoleResource::make($role)->toArray($request);
            $payload['can_assign'] = $actingUser->can('assign', $role);

            if (!$withPermissions) {
                return $payload;
            }

            $payload['permissions'] = $role->permissions
                ->map(fn (Permission $permission): array => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'group' => $permission->group,
                ])
                ->all();

            return $payload;
        })->all();
    }
}
