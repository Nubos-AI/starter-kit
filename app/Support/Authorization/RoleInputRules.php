<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\Authorization\RoleAuthority;
use App\Enums\Authorization\RoleScope;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class RoleInputRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function all(User $actingUser): array
    {
        return array_merge($this->permissionIds('sometimes'), $this->fieldPermissions('sometimes'), [
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', Rule::enum(RoleScope::class)->only($this->assignableScopes($actingUser))],
            'authority' => ['nullable', Rule::enum(RoleAuthority::class)],
            'is_system' => ['sometimes', 'boolean'],
            'grants_subteam_visibility' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function permissionIds(string $presence): array
    {
        return [
            'permission_ids' => [$presence, 'array'],
            'permission_ids.*' => ['string', $this->existsInBoundTenant('permissions')],
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function deniedPermissionIds(string $presence): array
    {
        return [
            'denied_permission_ids' => [$presence, 'array'],
            'denied_permission_ids.*' => ['string', $this->existsInBoundTenant('permissions')],
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function roleIds(string $presence): array
    {
        return [
            'role_ids' => [$presence, 'array'],
            'role_ids.*' => ['string', $this->existsInBoundTenant('roles')],
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function fieldPermissions(string $presence): array
    {
        return [
            'field_permissions' => [$presence, 'array'],
            'field_permissions.*.field_definition_id' => ['required', 'ulid', $this->existsInBoundTenant('field_definitions')],
            'field_permissions.*.can_read' => ['required', 'boolean'],
            'field_permissions.*.can_write' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<RoleScope>
     */
    public function assignableScopes(User $actingUser): array
    {
        if ($actingUser->hasRoleWithAuthority(RoleAuthority::SuperAdmin)) {
            return RoleScope::cases();
        }

        return array_values(array_filter(
            RoleScope::cases(),
            fn (RoleScope $scope): bool => $scope !== RoleScope::Platform,
        ));
    }

    /**
     * @return list<RoleAuthority>
     */
    public function assignableAuthorities(User $actingUser): array
    {
        if (!$actingUser->isEscalatedAuthority()) {
            return [];
        }

        if ($actingUser->hasRoleWithAuthority(RoleAuthority::SuperAdmin)) {
            return RoleAuthority::cases();
        }

        return array_values(array_filter(
            RoleAuthority::cases(),
            fn (RoleAuthority $authority): bool => $authority !== RoleAuthority::SuperAdmin,
        ));
    }

    private function existsInBoundTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', (string) TenantContext::currentId());
    }
}
