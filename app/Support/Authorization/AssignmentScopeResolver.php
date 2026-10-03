<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\Authorization\RoleScope;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AssignmentScopeResolver
{
    public function resolve(Role $role): ?Model
    {
        if ($role->scope === RoleScope::Platform) {
            return null;
        }

        if ($role->scope !== RoleScope::Tenant) {
            throw ValidationException::withMessages([
                'role_ids' => __('i18n.backend.support.authorization.assignment_scope_resolver.team_scoped_roles_require_an_explicit_selection'),
            ]);
        }

        $tenant = app()->bound('current_tenant') ? app('current_tenant') : null;

        if (!$tenant instanceof Tenant) {
            throw ValidationException::withMessages([
                'role_ids' => __('i18n.backend.support.authorization.assignment_scope_resolver.a_tenant_scoped_role_cannot_be_assigned_without_an'),
            ]);
        }

        return $tenant;
    }
}
