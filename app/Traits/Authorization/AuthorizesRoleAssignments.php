<?php

declare(strict_types=1);

namespace App\Traits\Authorization;

use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

trait AuthorizesRoleAssignments
{
    /**
     * @param  list<string>  $roleIds
     *
     * @throws AuthorizationException
     */
    protected function authorizeRoleAssignments(array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }

        $tenantId = TenantContext::currentId();

        if ($tenantId === null) {
            throw new AuthorizationException(__('i18n.backend.traits.authorization.authorizes_role_assignments.roles_can_only_be_assigned_within_a_bound_tenant'));
        }

        $roles = Role::withoutTenantScope()
            ->whereKey($roleIds)
            ->where('tenant_id', $tenantId)
            ->get();

        if ($roles->count() !== count(array_unique($roleIds))) {
            throw new AuthorizationException(__('i18n.backend.traits.authorization.authorizes_role_assignments.at_least_one_of_the_selected_roles_does_not'));
        }

        foreach ($roles as $role) {
            $this->authorize('assign', $role);
        }
    }
}
