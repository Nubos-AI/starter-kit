<?php

declare(strict_types=1);

namespace App\Actions\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\RoleAuthorityGuard;
use App\Support\Authorization\RoleInputRules;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CreateRoleAction
{
    public function __construct(
        private readonly SyncRolePermissionsAction $syncRolePermissions,
        private readonly RoleAuthorityGuard $authorityGuard,
        private readonly RoleInputRules $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(User $actingUser, array $input): Role
    {
        $validated = Validator::make($input, $this->rules->all($actingUser))->validate();

        $this->authorityGuard->assertMayChange($actingUser, $validated, null);
        $this->authorityGuard->assertMayChangeSubteamVisibility($actingUser, $validated, false);

        $attributes = [
            'name' => $validated['name'],
            'scope' => $validated['scope'],
            'authority' => $validated['authority'] ?? null,
        ];

        if (array_key_exists('grants_subteam_visibility', $validated)) {
            $attributes['grants_subteam_visibility'] = (bool) $validated['grants_subteam_visibility'];
        }

        $role = Role::query()->create($attributes);

        if (array_key_exists('permission_ids', $validated)) {
            $this->syncRolePermissions->execute($role, ['permission_ids' => array_values($validated['permission_ids'])]);
        }

        return $role;
    }
}
