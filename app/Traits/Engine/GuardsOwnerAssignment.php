<?php

declare(strict_types=1);

namespace App\Traits\Engine;

use App\Enums\Authorization\RoleAuthority;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait GuardsOwnerAssignment
{
    private function ownerRule(string $tenantId): Exists
    {
        return Rule::exists('users', 'id')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at');
    }

    /**
     * @throws AuthorizationException
     */
    private function guardOwnerAssignment(?User $user): void
    {
        if ($user === null || $this->isAutomationContext()) {
            return;
        }

        if ($user->hasRoleWithAuthority(RoleAuthority::SuperAdmin) || $user->hasRoleWithAuthority(RoleAuthority::ScopeAdmin)) {
            return;
        }

        throw new AuthorizationException(__('i18n.backend.actions.engine.update_record_action.you_may_not_change_this_record_s_owner'));
    }

    private function isAutomationContext(): bool
    {
        $marker = app()->bound('current_automation_actor') ? app('current_automation_actor') : null;

        if (!is_array($marker)) {
            return false;
        }

        $automationId = $marker['id'] ?? null;

        return is_string($automationId) && $automationId !== '';
    }
}
