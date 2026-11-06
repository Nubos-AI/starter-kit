<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Contracts\Authorization\PermissionHolderInterface;
use App\Enums\Authorization\PermissionEffect;
use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class AuthorizationDirectory
{
    /**
     * @return list<string>
     */
    public function permissionIdsOfRole(Role $role): array
    {
        /** @var list<string> $ids */
        $ids = $role->permissions()
            ->pluck('permissions.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }

    /**
     * @param  list<string>  $permissionIds
     * @return EloquentCollection<int, Permission>
     */
    public function permissionsByIds(array $permissionIds): EloquentCollection
    {
        if ($permissionIds === []) {
            return new EloquentCollection;
        }

        return Permission::query()->whereKey($permissionIds)->get();
    }

    /**
     * @param  list<string>  $permissionIds
     * @return list<string>
     */
    public function existingPermissionIds(array $permissionIds): array
    {
        /** @var list<string> $ids */
        $ids = $this->permissionsByIds($permissionIds)
            ->map(static fn (Permission $permission): string => (string) $permission->getKey())
            ->all();

        return $ids;
    }

    /**
     * @return array<string, FieldPermission>
     */
    public function fieldGrantsOfRole(Role $role): array
    {
        /** @var array<string, FieldPermission> $grants */
        $grants = FieldPermission::query()
            ->where('role_id', $role->getKey())
            ->get()
            ->keyBy('field_definition_id')
            ->all();

        return $grants;
    }

    /**
     * @param  list<string>  $fieldDefinitionIds
     * @return EloquentCollection<int, FieldPermission>
     */
    public function fieldGrantsOfFields(array $fieldDefinitionIds): EloquentCollection
    {
        if ($fieldDefinitionIds === []) {
            return new EloquentCollection;
        }

        return FieldPermission::query()
            ->whereIn('field_definition_id', $fieldDefinitionIds)
            ->get(['field_definition_id', 'role_id', 'can_read', 'can_write']);
    }

    /**
     * @return EloquentCollection<int, Team>
     */
    public function teamsScopingAssignmentsOf(User $user): EloquentCollection
    {
        $teamIds = RoleAssignment::query()
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->where('scope_type', (new Team)->getMorphClass())
            ->whereNotNull('scope_id')
            ->pluck('scope_id')
            ->unique();

        if ($teamIds->isEmpty()) {
            return new EloquentCollection;
        }

        /** @var EloquentCollection<int, Team> $teams */
        $teams = Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->whereNull('deleted_at')
            ->whereKey($teamIds)
            ->get();

        return $teams;
    }

    /**
     * @param  list<string>  $fieldDefinitionIds
     * @return EloquentCollection<int, FieldDefinition>
     */
    public function fieldDefinitionsByIds(array $fieldDefinitionIds): EloquentCollection
    {
        if ($fieldDefinitionIds === []) {
            return new EloquentCollection;
        }

        return FieldDefinition::query()->whereKey($fieldDefinitionIds)->get();
    }

    /**
     * @param  list<string>  $roleIds
     * @return EloquentCollection<int, Role>
     */
    public function rolesByIds(array $roleIds): EloquentCollection
    {
        if ($roleIds === []) {
            return new EloquentCollection;
        }

        return Role::query()->whereKey($roleIds)->get();
    }

    /**
     * @param  list<string>  $teamIds
     * @return EloquentCollection<int, Role>
     */
    public function rolesAssignedToTeams(array $teamIds): EloquentCollection
    {
        if ($teamIds === []) {
            return new EloquentCollection;
        }

        $roleIds = RoleAssignment::query()
            ->where('model_type', (new Team)->getMorphClass())
            ->whereIn('model_id', $teamIds)
            ->pluck('role_id')
            ->unique()
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        /** @var list<string> $roleIds */
        return $this->rolesByIds($roleIds);
    }

    /**
     * @return list<string>
     */
    public function roleIdsAssignedTo(Team|User $holder): array
    {
        /** @var list<string> $ids */
        $ids = $holder->roleAssignments()
            ->pluck('role_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }

    /**
     * @return list<string>
     */
    public function deniedPermissionIdsOf(PermissionHolderInterface $holder): array
    {
        /** @var list<string> $ids */
        $ids = $holder->permissionOverrides()
            ->where('effect', PermissionEffect::Deny->value)
            ->pluck('permission_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }
}
