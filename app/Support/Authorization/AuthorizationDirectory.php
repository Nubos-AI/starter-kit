<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Contracts\Authorization\PermissionHolderInterface;
use App\Enums\Authorization\PermissionEffect;
use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\Permission;
use App\Models\PermissionOverride;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\User;
use App\Support\Http\IdentifierList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

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
     * @return list<string>
     */
    public function restrictedFieldIds(): array
    {
        /** @var list<string> $ids */
        $ids = FieldPermission::query()
            ->distinct()
            ->pluck('field_definition_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return $ids;
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
        return $this->rolesByIds(IdentifierList::from(array_merge([], ...array_values($this->roleIdsByTeam($teamIds)))));
    }

    /**
     * @param  list<string>  $teamIds
     * @return array<string, list<string>>
     */
    public function roleIdsByTeam(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        return RoleAssignment::query()
            ->where('model_type', (new Team)->getMorphClass())
            ->whereIn('model_id', $teamIds)
            ->get(['model_id', 'role_id'])
            ->groupBy('model_id')
            ->map(static fn (Collection $rows): array => IdentifierList::from($rows->pluck('role_id')->all()))
            ->all();
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
    public function directRoleIdsOf(User $user): array
    {
        /** @var list<string> $ids */
        $ids = $user->roleAssignments()
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('scope_type')
                ->orWhere('scope_type', '!=', (new Team)->getMorphClass()))
            ->pluck('role_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * @return list<array{role_id: string, team_id: string}>
     */
    public function teamRolesOf(User $user): array
    {
        /** @var list<array{role_id: string, team_id: string}> $teamRoles */
        $teamRoles = $user->roleAssignments()
            ->where('scope_type', (new Team)->getMorphClass())
            ->whereNotNull('scope_id')
            ->orderBy('id')
            ->get(['role_id', 'scope_id'])
            ->map(static fn (RoleAssignment $assignment): array => [
                'role_id' => (string) $assignment->role_id,
                'team_id' => (string) $assignment->scope_id,
            ])
            ->values()
            ->all();

        return $teamRoles;
    }

    /**
     * @return list<string>
     */
    public function deniedPermissionIdsOf(PermissionHolderInterface $holder): array
    {
        /** @var list<string> $ids */
        $ids = $holder->permissionOverrides()
            ->where('effect', PermissionEffect::Deny->value)
            ->whereNull('scope_type')
            ->whereNull('scope_id')
            ->pluck('permission_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }

    /**
     * @param  list<string>  $holderKeys
     * @return array<string, list<string>>
     */
    public function allowedPermissionIdsByHolder(string $morphClass, array $holderKeys): array
    {
        if ($holderKeys === []) {
            return [];
        }

        return PermissionOverride::query()
            ->where('model_type', $morphClass)
            ->whereIn('model_id', $holderKeys)
            ->where('effect', PermissionEffect::Allow->value)
            ->get(['model_id', 'permission_id'])
            ->groupBy('model_id')
            ->map(static fn (Collection $rows): array => IdentifierList::from($rows->pluck('permission_id')->all()))
            ->all();
    }
}
