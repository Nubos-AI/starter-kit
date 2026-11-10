<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Contracts\Authorization\PermissionHolderInterface;
use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\Authorization\AuthorizationDirectory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

class FakeAuthorizationDirectory extends AuthorizationDirectory
{
    /**
     * @var list<string>
     */
    public array $askedFor = [];

    /**
     * @var array<string, list<string>>
     */
    private array $permissionIdsByRole = [];

    /**
     * @var array<string, Permission>
     */
    private array $permissions = [];

    /**
     * @var array<string, array<string, FieldPermission>>
     */
    private array $fieldGrantsByRole = [];

    /**
     * @var array<string, FieldDefinition>
     */
    private array $fieldDefinitions = [];

    /**
     * @var array<string, Role>
     */
    private array $roles = [];

    /**
     * @var array<string, list<string>>
     */
    private array $roleIdsByTeam = [];

    /**
     * @var array<string, list<string>>
     */
    private array $roleIdsByHolder = [];

    /**
     * @var array<string, list<string>>
     */
    private array $deniedPermissionIdsByHolder = [];

    /**
     * @var array<string, list<string>>
     */
    private array $allowedPermissionIdsByHolder = [];

    /**
     * @var list<FieldPermission>
     */
    private array $fieldGrants = [];

    /**
     * @var array<string, list<Team>>
     */
    private array $scopedTeamsByUser = [];

    /**
     * @var array<string, list<array{role_id: string, team_id: string}>>
     */
    private array $teamRolesByUser = [];

    /**
     * @param  list<Permission>  $permissions
     */
    public function withPermissions(array $permissions): self
    {
        foreach ($permissions as $permission) {
            $this->permissions[(string) $permission->getKey()] = $permission;
        }

        return $this;
    }

    /**
     * @param  list<Role>  $roles
     */
    public function withRoles(array $roles): self
    {
        foreach ($roles as $role) {
            $this->roles[(string) $role->getKey()] = $role;
        }

        return $this;
    }

    /**
     * @param  list<string>  $permissionIds
     */
    public function withRolePermissions(Role $role, array $permissionIds): self
    {
        $this->permissionIdsByRole[(string) $role->getKey()] = $permissionIds;

        return $this;
    }

    /**
     * @param  list<FieldPermission>  $grants
     */
    public function withFieldGrants(Role $role, array $grants): self
    {
        $keyed = [];

        foreach ($grants as $grant) {
            $keyed[(string) $grant->field_definition_id] = $grant;
        }

        $this->fieldGrantsByRole[(string) $role->getKey()] = $keyed;

        return $this;
    }

    /**
     * @param  list<FieldDefinition>  $fields
     */
    public function withFieldDefinitions(array $fields): self
    {
        foreach ($fields as $field) {
            $this->fieldDefinitions[(string) $field->getKey()] = $field;
        }

        return $this;
    }

    /**
     * @param  list<string>  $roleIds
     */
    public function withTeamRoles(Team $team, array $roleIds): self
    {
        $this->roleIdsByTeam[(string) $team->getKey()] = $roleIds;

        return $this;
    }

    /**
     * @param  list<string>  $roleIds
     */
    public function withAssignedRoles(Team|User $holder, array $roleIds): self
    {
        $this->roleIdsByHolder[$this->holderKey($holder)] = $roleIds;

        return $this;
    }

    /**
     * @param  list<string>  $permissionIds
     */
    public function withDeniedPermissions(PermissionHolderInterface $holder, array $permissionIds): self
    {
        $this->deniedPermissionIdsByHolder[$this->holderKey($holder)] = $permissionIds;

        return $this;
    }

    /**
     * @param  list<string>  $permissionIds
     */
    public function withAllowedPermissions(PermissionHolderInterface $holder, array $permissionIds): self
    {
        $this->allowedPermissionIdsByHolder[$holder instanceof Model ? (string) $holder->getKey() : spl_object_hash($holder)] = $permissionIds;

        return $this;
    }

    /**
     * @param  list<FieldPermission>  $grants
     */
    public function withFieldGrantRows(array $grants): self
    {
        $this->fieldGrants = $grants;

        return $this;
    }

    /**
     * @param  list<Team>  $teams
     */
    public function withScopedTeams(User $user, array $teams): self
    {
        $this->scopedTeamsByUser[(string) $user->getKey()] = $teams;

        return $this;
    }

    public function fieldGrantsOfFields(array $fieldDefinitionIds): EloquentCollection
    {
        $this->askedFor[] = 'fieldGrantsOfFields';

        /** @var EloquentCollection<int, FieldPermission> $resolved */
        $resolved = new EloquentCollection(array_values(array_filter(
            $this->fieldGrants,
            static fn (FieldPermission $grant): bool => in_array((string) $grant->field_definition_id, $fieldDefinitionIds, true),
        )));

        return $resolved;
    }

    public function teamsScopingAssignmentsOf(User $user): EloquentCollection
    {
        $this->askedFor[] = 'teamsScopingAssignmentsOf';

        /** @var EloquentCollection<int, Team> $resolved */
        $resolved = new EloquentCollection($this->scopedTeamsByUser[(string) $user->getKey()] ?? []);

        return $resolved;
    }

    public function permissionIdsOfRole(Role $role): array
    {
        $this->askedFor[] = 'permissionIdsOfRole';

        return $this->permissionIdsByRole[(string) $role->getKey()] ?? [];
    }

    public function permissionsByIds(array $permissionIds): EloquentCollection
    {
        $this->askedFor[] = 'permissionsByIds';

        /** @var EloquentCollection<int, Permission> $resolved */
        $resolved = new EloquentCollection(array_values(array_filter(array_map(
            fn (string $id): ?Permission => $this->permissions[$id] ?? null,
            $permissionIds,
        ))));

        return $resolved;
    }

    public function existingPermissionIds(array $permissionIds): array
    {
        $this->askedFor[] = 'existingPermissionIds';

        return array_values(array_filter(
            $permissionIds,
            fn (string $id): bool => isset($this->permissions[$id]),
        ));
    }

    public function fieldGrantsOfRole(Role $role): array
    {
        $this->askedFor[] = 'fieldGrantsOfRole';

        return $this->fieldGrantsByRole[(string) $role->getKey()] ?? [];
    }

    public function restrictedFieldIds(): array
    {
        $this->askedFor[] = 'restrictedFieldIds';

        $ids = array_merge(
            array_map(static fn (FieldPermission $grant): string => (string) $grant->field_definition_id, $this->fieldGrants),
            ...array_values(array_map(static fn (array $grants): array => array_map('strval', array_keys($grants)), $this->fieldGrantsByRole)),
        );

        return array_values(array_unique($ids));
    }

    public function fieldDefinitionsByIds(array $fieldDefinitionIds): EloquentCollection
    {
        $this->askedFor[] = 'fieldDefinitionsByIds';

        /** @var EloquentCollection<int, FieldDefinition> $resolved */
        $resolved = new EloquentCollection(array_values(array_filter(array_map(
            fn (string $id): ?FieldDefinition => $this->fieldDefinitions[$id] ?? null,
            $fieldDefinitionIds,
        ))));

        return $resolved;
    }

    public function rolesByIds(array $roleIds): EloquentCollection
    {
        $this->askedFor[] = 'rolesByIds';

        /** @var EloquentCollection<int, Role> $resolved */
        $resolved = new EloquentCollection(array_values(array_filter(array_map(
            fn (string $id): ?Role => $this->roles[$id] ?? null,
            $roleIds,
        ))));

        return $resolved;
    }

    public function rolesAssignedToTeams(array $teamIds): EloquentCollection
    {
        $this->askedFor[] = 'rolesAssignedToTeams';

        $roleIds = [];

        foreach ($teamIds as $teamId) {
            foreach ($this->roleIdsByTeam[$teamId] ?? [] as $roleId) {
                $roleIds[] = $roleId;
            }
        }

        return $this->rolesByIds(array_values(array_unique($roleIds)));
    }

    /**
     * @param  list<array{role_id: string, team_id: string}>  $teamRoles
     */
    public function withTeamRolesOf(User $user, array $teamRoles): self
    {
        $this->teamRolesByUser[(string) $user->getKey()] = $teamRoles;

        return $this;
    }

    public function directRoleIdsOf(User $user): array
    {
        $this->askedFor[] = 'directRoleIdsOf';

        return $this->roleIdsByHolder[$this->holderKey($user)] ?? [];
    }

    public function teamRolesOf(User $user): array
    {
        $this->askedFor[] = 'teamRolesOf';

        return $this->teamRolesByUser[(string) $user->getKey()] ?? [];
    }

    public function roleIdsAssignedTo(Team|User $holder): array
    {
        $this->askedFor[] = 'roleIdsAssignedTo';

        return $this->roleIdsByHolder[$this->holderKey($holder)] ?? [];
    }

    public function deniedPermissionIdsOf(PermissionHolderInterface $holder): array
    {
        $this->askedFor[] = 'deniedPermissionIdsOf';

        return $this->deniedPermissionIdsByHolder[$this->holderKey($holder)] ?? [];
    }

    public function roleIdsByTeam(array $teamIds): array
    {
        $this->askedFor[] = 'roleIdsByTeam';

        return array_intersect_key($this->roleIdsByTeam, array_flip($teamIds));
    }

    public function allowedPermissionIdsByHolder(string $morphClass, array $holderKeys): array
    {
        $this->askedFor[] = 'allowedPermissionIdsByHolder';

        return array_intersect_key($this->allowedPermissionIdsByHolder, array_flip($holderKeys));
    }

    private function holderKey(object $holder): string
    {
        return $holder::class.'|'.(method_exists($holder, 'getKey') ? (string) $holder->getKey() : spl_object_hash($holder));
    }
}
