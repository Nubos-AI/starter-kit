<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class PermissionSubsetGuard
{
    public function __construct(private readonly AuthorizationDirectory $directory) {}

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

        if ($role->authority !== null && !$actor->isEscalatedAuthority()) {
            throw new AuthorizationException($this->message());
        }

        $this->assertHoldsAll($actor, $role->permissions);

        $roleRestrictions = $this->directory->fieldGrantsOfRole($role);
        $granted = [];

        foreach ($actor->rolesFor() as $actorRole) {
            foreach (array_keys($this->directory->fieldGrantsOfRole($actorRole)) as $fieldId) {
                $restriction = $roleRestrictions[$fieldId] ?? null;

                $granted[$fieldId] = [
                    'read' => $restriction->can_read ?? true,
                    'write' => $restriction->can_write ?? true,
                ];
            }
        }

        $this->assertHoldsAllFields($actor, $granted);
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

    /**
     * @param  array<string, array{read: bool, write: bool}>  $fieldGrants
     *
     * @throws AuthorizationException
     */
    private function assertHoldsAllFields(User $actor, array $fieldGrants): void
    {
        if ($fieldGrants === []) {
            return;
        }

        $resolver = FieldVisibilityResolver::forRequest();

        foreach ($this->directory->fieldDefinitionsByIds(array_keys($fieldGrants)) as $field) {
            $wanted = $fieldGrants[(string) $field->getKey()];

            $deniedRead = $wanted['read']
                && in_array($field->key, $resolver->forbiddenReadFieldKeys($actor, $field->object_type_id), true);

            $deniedWrite = $wanted['write']
                && in_array($field->key, $resolver->forbiddenWriteFieldKeys($actor, $field->object_type_id), true);

            if ($deniedRead || $deniedWrite) {
                throw new AuthorizationException($this->fieldMessage());
            }
        }
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     *
     * @throws AuthorizationException
     */
    private function assertHoldsAll(User $actor, Collection $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!$actor->hasPermission($permission->name)) {
                throw new AuthorizationException($this->message());
            }
        }
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
