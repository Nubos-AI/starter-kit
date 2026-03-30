<?php

declare(strict_types=1);

namespace App\Traits\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

trait HasRoles
{
    /**
     * @return MorphToMany<Role, $this>
     */
    public function roles(): MorphToMany
    {
        return $this->morphToMany(Role::class, 'model', 'role_assignments')
            ->using(RoleAssignment::class)
            ->withPivot('scope_type', 'scope_id')
            ->withTimestamps();
    }

    public function assignRole(Role $role, ?Model $scope = null): void
    {
        $this->roles()->attach($role->id, [
            'scope_type' => $scope?->getMorphClass(),
            'scope_id' => $scope?->getKey(),
        ]);
    }

    public function removeRole(Role $role, ?Model $scope = null): void
    {
        $this->roles()
            ->wherePivot('scope_type', $scope?->getMorphClass())
            ->wherePivot('scope_id', $scope?->getKey())
            ->detach($role->id);
    }

    public function hasRole(string $roleName, ?Model $scope = null): bool
    {
        return $this->roles()
            ->where('name', $roleName)
            ->wherePivot('scope_type', $scope?->getMorphClass())
            ->wherePivot('scope_id', $scope?->getKey())
            ->exists();
    }

    public function hasPermission(string $permissionName, ?Model $scope = null): bool
    {
        return $this->rolesFor($scope)
            ->flatMap(fn (Role $role): Collection => $role->permissions->pluck('name'))
            ->contains($permissionName);
    }

    /**
     * @return Collection<int, Role>
     */
    public function rolesFor(?Model $scope = null): Collection
    {
        return $this->roles()
            ->wherePivot('scope_type', $scope?->getMorphClass())
            ->wherePivot('scope_id', $scope?->getKey())
            ->with('permissions')
            ->get();
    }

    /**
     * @return Collection<int, Permission>
     */
    public function permissionsFor(?Model $scope = null): Collection
    {
        return $this->rolesFor($scope)
            ->flatMap(fn (Role $role): Collection => $role->permissions)
            ->unique('id');
    }
}
