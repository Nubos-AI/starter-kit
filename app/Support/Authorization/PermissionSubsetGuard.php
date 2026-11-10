<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\Authorization\RoleAuthority;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Http\IdentifierList;
use App\Support\Teams\TeamMembershipDirectory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class PermissionSubsetGuard
{
    public function __construct(
        private readonly AuthorizationDirectory $directory,
        private readonly TeamMembershipDirectory $memberships,
        private readonly PermissionResolver $permissionResolver,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function assertMayEditRole(Role $role): void
    {
        $actor = $this->actingUser();

        if (!$actor instanceof User) {
            return;
        }

        if (!$this->mayAssignRole($actor, $role)) {
            throw new AuthorizationException(__('i18n.backend.support.authorization.permission_subset_guard.you_cannot_change_a_role_beyond_your_own_rights'));
        }
    }

    /**
     * @param  list<string>  $permissionIds
     *
     * @throws AuthorizationException
     */
    public function assertMayGrantPermissions(Role $role, array $permissionIds): void
    {
        $actor = $this->actingUser();

        if (!$actor instanceof User) {
            return;
        }

        $held = $this->directory->permissionIdsOfRole($role);
        $added = array_values(array_diff($permissionIds, $held));

        if ($added === []) {
            return;
        }

        $resolved = $this->directory->permissionsByIds($added);

        if ($resolved->count() !== count(array_unique($added))) {
            throw new AuthorizationException($this->message());
        }

        $this->assertHoldsAll($actor, $resolved);
    }

    /**
     * @param  list<string>  $permissionIds
     *
     * @throws AuthorizationException
     */
    public function assertMayOverridePermissions(array $permissionIds): void
    {
        $actor = $this->actingUser();

        if (!$actor instanceof User || $permissionIds === []) {
            return;
        }

        $this->assertHoldsAll($actor, $this->directory->permissionsByIds($permissionIds));
    }

    /**
     * @throws AuthorizationException
     */
    public function assertMayAssignRole(Role $role): void
    {
        $actor = $this->actingUser();

        if (!$actor instanceof User) {
            return;
        }

        if (!$this->mayGrantRolePermissions($actor, $role)) {
            throw new AuthorizationException($this->message());
        }

        $this->assertHoldsAllFields($actor, $this->restrictedFieldGrantsOf($role, $this->directory->restrictedFieldIds()));
    }

    public function mayAssignRole(User $actor, Role $role): bool
    {
        return $this->mayGrantRolePermissions($actor, $role)
            && $this->holdsAllFields($actor, $this->restrictedFieldGrantsOf($role, $this->directory->restrictedFieldIds()));
    }

    public function maySteerAccount(User $actor, User $target): bool
    {
        $key = (string) $target->getKey();

        return $this->steerableAccountKeys(
            $actor,
            [$key],
            [$key => $this->directory->roleIdsAssignedTo($target)],
            [$key => $this->memberships->teamIdsOf($target)],
        ) === [$key];
    }

    /**
     * @param  list<string>  $userKeys
     * @param  array<string, list<string>>  $roleIdsByUser
     * @param  array<string, list<string>>  $teamIdsByUser
     * @return list<string>
     */
    public function steerableAccountKeys(User $actor, array $userKeys, array $roleIdsByUser, array $teamIdsByUser): array
    {
        if ($userKeys === [] || $actor->hasRoleWithAuthority(RoleAuthority::SuperAdmin)) {
            return $userKeys;
        }

        $teamIdsByUser = array_intersect_key($teamIdsByUser, array_flip($userKeys));
        $teamIds = IdentifierList::from(array_merge([], ...array_values($teamIdsByUser)));
        $roleIdsByTeam = $this->directory->roleIdsByTeam($teamIds);
        $allowedByHolder = [
            ...$this->directory->allowedPermissionIdsByHolder((new User)->getMorphClass(), $userKeys),
            ...$this->directory->allowedPermissionIdsByHolder((new Team)->getMorphClass(), $teamIds),
        ];

        $heldRoleIds = [];
        $heldAllowedIds = [];

        foreach ($userKeys as $key) {
            $teamIdsOfUser = $teamIdsByUser[$key] ?? [];

            $heldRoleIds[$key] = IdentifierList::from(array_merge(
                $roleIdsByUser[$key] ?? [],
                ...array_map(static fn (string $teamId): array => $roleIdsByTeam[$teamId] ?? [], $teamIdsOfUser),
            ));
            $heldAllowedIds[$key] = IdentifierList::from(array_merge(
                [],
                ...array_map(static fn (string $holder): array => $allowedByHolder[$holder] ?? [], [$key, ...$teamIdsOfUser]),
            ));
        }

        $heldRoles = $this->directory
            ->rolesByIds(IdentifierList::from(array_merge([], ...array_values($heldRoleIds))))
            ->loadMissing('permissions');
        $restrictedFieldIds = $heldRoles->isEmpty() ? [] : $this->directory->restrictedFieldIds();

        $assignableRoles = $heldRoles
            ->mapWithKeys(fn (Role $role): array => [
                (string) $role->getKey() => $this->mayGrantRolePermissions($actor, $role)
                    && $this->holdsAllFields($actor, $this->restrictedFieldGrantsOf($role, $restrictedFieldIds)),
            ])
            ->all();

        $heldPermissions = $this->directory
            ->permissionsByIds(IdentifierList::from(array_merge([], ...array_values($heldAllowedIds))))
            ->mapWithKeys(fn (Permission $permission): array => [(string) $permission->getKey() => $this->holdsTenantWide($actor, $permission->name)])
            ->all();

        return array_values(array_filter(
            $userKeys,
            static fn (string $key): bool => array_all($heldRoleIds[$key], static fn (string $id): bool => $assignableRoles[$id] ?? true)
                && array_all($heldAllowedIds[$key], static fn (string $id): bool => $heldPermissions[$id] ?? true),
        ));
    }

    /**
     * @param  list<array{field_definition_id: string, can_read: bool, can_write: bool}>  $permissions
     *
     * @throws AuthorizationException
     */
    public function assertMayGrantFieldPermissions(Role $role, array $permissions): void
    {
        $actor = $this->actingUser();

        if (!$actor instanceof User) {
            return;
        }

        $held = $this->directory->fieldGrantsOfRole($role);
        $added = [];

        foreach ($permissions as $permission) {
            $current = $held[$permission['field_definition_id']] ?? null;

            $read = $permission['can_read'] && !($current->can_read ?? true);
            $write = $permission['can_write'] && !($current->can_write ?? true);

            if ($read || $write) {
                $added[$permission['field_definition_id']] = ['read' => $read, 'write' => $write];
            }
        }

        $this->assertHoldsAllFields($actor, $added);
    }

    private function mayGrantRolePermissions(User $actor, Role $role): bool
    {
        if ($role->authority === RoleAuthority::SuperAdmin && !$actor->hasRoleWithAuthority(RoleAuthority::SuperAdmin)) {
            return false;
        }

        if ($role->authority !== null && !$actor->isEscalatedAuthority()) {
            return false;
        }

        return $this->holdsAll($actor, $role->permissions);
    }

    /**
     * @param  list<string>  $restrictedFieldIds
     * @return array<string, array{read: bool, write: bool}>
     */
    private function restrictedFieldGrantsOf(Role $role, array $restrictedFieldIds): array
    {
        $roleRestrictions = $this->directory->fieldGrantsOfRole($role);
        $granted = [];

        foreach ($restrictedFieldIds as $fieldId) {
            $restriction = $roleRestrictions[$fieldId] ?? null;

            $granted[$fieldId] = [
                'read' => $restriction->can_read ?? true,
                'write' => $restriction->can_write ?? true,
            ];
        }

        return $granted;
    }

    /**
     * @param  array<string, array{read: bool, write: bool}>  $fieldGrants
     *
     * @throws AuthorizationException
     */
    private function assertHoldsAllFields(User $actor, array $fieldGrants): void
    {
        if (!$this->holdsAllFields($actor, $fieldGrants)) {
            throw new AuthorizationException($this->fieldMessage());
        }
    }

    /**
     * @param  array<string, array{read: bool, write: bool}>  $fieldGrants
     */
    private function holdsAllFields(User $actor, array $fieldGrants): bool
    {
        if ($fieldGrants === []) {
            return true;
        }

        $resolver = FieldVisibilityResolver::forRequest();

        foreach ($this->directory->fieldDefinitionsByIds(array_keys($fieldGrants)) as $field) {
            $wanted = $fieldGrants[(string) $field->getKey()];

            $deniedRead = $wanted['read']
                && in_array($field->key, $resolver->forbiddenReadFieldKeys($actor, $field->object_type_id), true);

            $deniedWrite = $wanted['write']
                && in_array($field->key, $resolver->forbiddenWriteFieldKeys($actor, $field->object_type_id), true);

            if ($deniedRead || $deniedWrite) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     *
     * @throws AuthorizationException
     */
    private function assertHoldsAll(User $actor, Collection $permissions): void
    {
        if (!$this->holdsAll($actor, $permissions)) {
            throw new AuthorizationException($this->message());
        }
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     */
    private function holdsAll(User $actor, Collection $permissions): bool
    {
        return $permissions->every(
            fn (Permission $permission): bool => $this->holdsTenantWide($actor, $permission->name),
        );
    }

    private function holdsTenantWide(User $actor, string $ability): bool
    {
        $tenant = $this->tenantOf($actor);

        return $tenant instanceof Tenant
            && $this->permissionResolver->verdictFor($actor, $ability, $tenant) === true;
    }

    private function tenantOf(User $actor): ?Tenant
    {
        $bound = TenantContext::current();

        if ($bound instanceof Tenant && (string) $bound->getKey() === (string) $actor->tenant_id) {
            return $bound;
        }

        return $actor->tenant;
    }

    private function actingUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function message(): string
    {
        return __('i18n.backend.support.authorization.permission_subset_guard.you_cannot_grant_permissions_beyond_your_own');
    }

    private function fieldMessage(): string
    {
        return __('i18n.backend.support.authorization.permission_subset_guard.you_cannot_grant_field_permissions_beyond_your_own');
    }
}
