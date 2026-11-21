<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\Authorization\RoleScope;
use App\Models\Role;
use App\Models\Team;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AssignmentScopeResolver
{
    /**
     * @throws ValidationException
     */
    public function resolve(Role $role, ?string $teamId = null): ?Model
    {
        if ($role->scope === RoleScope::Platform) {
            return null;
        }

        if ($role->scope === RoleScope::Team && $teamId === null) {
            throw ValidationException::withMessages([
                'role_ids' => __('i18n.backend.support.authorization.assignment_scope_resolver.team_scoped_roles_require_an_explicit_selection'),
            ]);
        }

        $tenant = TenantContext::current();

        if (!$tenant instanceof Tenant) {
            throw ValidationException::withMessages([
                'role_ids' => __('i18n.backend.support.authorization.assignment_scope_resolver.a_tenant_scoped_role_cannot_be_assigned_without_an'),
            ]);
        }

        if ($role->scope !== RoleScope::Team) {
            return $tenant;
        }

        if ($role->authority !== null) {
            throw ValidationException::withMessages([
                'team_roles' => __('i18n.backend.support.authorization.assignment_scope_resolver.a_role_with_elevated_authority_cannot_be_assigned_per_team', ['value1' => $role->name]),
            ]);
        }

        return $this->teamOrFail($tenant, (string) $teamId);
    }

    /**
     * @throws ValidationException
     */
    private function teamOrFail(Tenant $tenant, string $teamId): Team
    {
        $team = Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', (string) $tenant->getKey())
            ->whereNull('deleted_at')
            ->whereKey($teamId)
            ->first();

        if (!$team instanceof Team) {
            throw ValidationException::withMessages([
                'team_roles' => __('i18n.backend.support.authorization.assignment_scope_resolver.the_selected_team_is_not_available'),
            ]);
        }

        return $team;
    }
}
