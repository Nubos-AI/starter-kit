<?php

declare(strict_types=1);

namespace App\Traits\Authorization;

use App\Enums\Authorization\PermissionEffect;
use App\Enums\Authorization\RoleAuthority;
use App\Models\Permission;
use App\Models\PermissionOverride;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

trait HasRoles
{
    /**
     * @var array<string, Collection<int, Role>>
     */
    protected array $resolvedRoles = [];

    /**
     * @var array<string, array<string, PermissionEffect>>
     */
    protected array $resolvedOverrides = [];

    /**
     * @var array<string, Collection<int, Role>>
     */
    protected array $resolvedInheritance = [];

    /**
     * @return MorphMany<RoleAssignment, $this>
     */
    public function roleAssignments(): MorphMany
    {
        return $this->morphMany(RoleAssignment::class, 'model');
    }

    public function assignRole(Role|string $role, ?Model $scope = null): RoleAssignment
    {
        $role = $this->resolveRole($role);

        $assignment = $this->roleAssignments()->firstOrCreate([
            'role_id' => $role->getKey(),
            'scope_type' => $scope?->getMorphClass(),
            'scope_id' => $scope?->getKey(),
        ]);

        $this->forgetResolvedRoles();

        return $assignment;
    }

    public function removeRole(Role|string $role, ?Model $scope = null): void
    {
        $role = $this->resolveRole($role);

        $query = $this->roleAssignments()->where('role_id', $role->getKey());

        if ($scope === null) {
            $query->whereNull('scope_type')->whereNull('scope_id');
        } else {
            $query->where('scope_type', $scope->getMorphClass())
                ->where('scope_id', $scope->getKey());
        }

        $query->delete();

        $this->forgetResolvedRoles();
    }

    public function hasRole(Role|string $role, ?Model $scope = null): bool
    {
        $name = $role instanceof Role ? $role->name : $role;

        return $this->rolesFor($scope)->contains(
            fn (Role $assigned): bool => $assigned->name === $name,
        );
    }

    public function hasRoleWithAuthority(RoleAuthority $authority, ?Model $scope = null): bool
    {
        return $this->rolesFor($scope)->contains(
            fn (Role $assigned): bool => $assigned->authority === $authority,
        );
    }

    public function isEscalatedAuthority(): bool
    {
        return $this->hasRoleWithAuthority(RoleAuthority::SuperAdmin)
            || $this->hasRoleWithAuthority(RoleAuthority::ScopeAdmin);
    }

    public function hasPermission(string $ability, ?Model $scope = null): bool
    {
        return app(PermissionResolver::class)->allows($this, $ability, $scope);
    }

    /**
     * @return MorphMany<PermissionOverride, $this>
     */
    public function permissionOverrides(): MorphMany
    {
        return $this->morphMany(PermissionOverride::class, 'model');
    }

    public function overridePermission(
        Permission|string $permission,
        PermissionEffect $effect,
        ?Model $scope = null,
    ): PermissionOverride {
        $permission = $this->resolvePermission($permission);

        $override = $this->permissionOverrides()->updateOrCreate([
            'permission_id' => $permission->getKey(),
            'scope_type' => $scope?->getMorphClass(),
            'scope_id' => $scope?->getKey(),
        ], [
            'effect' => $effect,
        ]);

        $this->forgetResolvedRoles();

        return $override;
    }

    public function removePermissionOverride(Permission|string $permission, ?Model $scope = null): void
    {
        $permission = $this->resolvePermission($permission);

        $query = $this->permissionOverrides()->where('permission_id', $permission->getKey());

        if ($scope === null) {
            $query->whereNull('scope_type')->whereNull('scope_id');
        } else {
            $query->where('scope_type', $scope->getMorphClass())
                ->where('scope_id', $scope->getKey());
        }

        $query->delete();

        $this->forgetResolvedRoles();
    }

    /**
     * @return Collection<int, Role>
     */
    public function rolesFor(?Model $scope = null): Collection
    {
        $inherited = $this->inheritedRolesFor($scope);

        if ($inherited->isEmpty()) {
            return $this->assignedRolesFor($scope);
        }

        return $this->assignedRolesFor($scope)
            ->merge($inherited)
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, Role>
     */
    public function assignedRolesFor(?Model $scope = null): Collection
    {
        $key = $this->scopeSignature($scope);

        if (isset($this->resolvedRoles[$key])) {
            return $this->resolvedRoles[$key];
        }

        $scopes = app(ScopeResolver::class)->resolve($scope, $this instanceof User ? $this : null);

        $assignments = RoleAssignment::query()
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey());

        $this->constrainToScopes($assignments, $scopes);

        $roleIds = $assignments->pluck('role_id')->unique();

        $roles = Role::query()
            ->whereKey($roleIds)
            ->with('permissions')
            ->get();

        return $this->resolvedRoles[$key] = $roles;
    }

    /**
     * @return Collection<int, Permission>
     */
    public function permissionsFor(?Model $scope = null): Collection
    {
        return $this->rolesFor($scope)
            ->flatMap(fn (Role $role): Collection => $role->permissions)
            ->unique('id')
            ->values();
    }

    public function forgetResolvedRoles(): void
    {
        $this->resolvedRoles = [];
        $this->resolvedOverrides = [];
        $this->resolvedInheritance = [];
    }

    /**
     * @return array<string, PermissionEffect>
     */
    public function assignedOverridesFor(?Model $scope = null): array
    {
        $key = $this->scopeSignature($scope);

        if (isset($this->resolvedOverrides[$key])) {
            return $this->resolvedOverrides[$key];
        }

        $scopes = app(ScopeResolver::class)->resolve($scope, $this instanceof User ? $this : null);

        $overrideQuery = PermissionOverride::query()
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey());

        $this->constrainToScopes($overrideQuery, $scopes);

        $overrides = $overrideQuery->with('permission')->get();

        $resolved = [];

        foreach ($overrides as $override) {
            $name = $override->permission?->name;

            if ($name === null) {
                continue;
            }

            if ($override->effect === PermissionEffect::Deny || !isset($resolved[$name])) {
                $resolved[$name] = $override->effect;
            }
        }

        return $this->resolvedOverrides[$key] = $resolved;
    }

    /**
     * @return Collection<int, Role>
     */
    protected function inheritedRolesFor(?Model $scope = null): Collection
    {
        return collect();
    }

    private function resolveRole(Role|string $role): Role
    {
        if ($role instanceof Role) {
            return $role;
        }

        return Role::query()->where('name', $role)->firstOrFail();
    }

    private function resolvePermission(Permission|string $permission): Permission
    {
        if ($permission instanceof Permission) {
            return $permission;
        }

        return Permission::query()->where('name', $permission)->firstOrFail();
    }

    private function scopeSignature(?Model $scope): string
    {
        if ($scope === null) {
            return 'context';
        }

        return ScopeResolver::signatureFor($scope);
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  Collection<int, Model>  $scopes
     */
    private function constrainToScopes(Builder $query, Collection $scopes): void
    {
        $query->where(static function (Builder $group) use ($scopes): void {
            $group->whereNull('scope_id');

            foreach ($scopes as $candidate) {
                $group->orWhere(static function (Builder $inner) use ($candidate): void {
                    $inner->where('scope_type', $candidate->getMorphClass())
                        ->where('scope_id', $candidate->getKey());
                });
            }
        });
    }
}
