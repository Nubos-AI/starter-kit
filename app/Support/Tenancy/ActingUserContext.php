<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

class ActingUserContext
{
    /**
     * @param  Closure(User): void  $callback
     *
     * @throws AuthorizationException
     */
    public function run(string $tenantId, string $actingUserId, Closure $callback): void
    {
        $previousTenant = app()->bound('current_tenant') ? app('current_tenant') : null;
        $previousUser = Auth::user();
        $hadHiddenTenantId = Context::hasHidden('tenant_id');
        $previousHiddenTenantId = Context::getHidden('tenant_id');

        try {
            $tenant = Tenant::query()->find($tenantId);

            if (!$tenant instanceof Tenant) {
                throw new AuthorizationException(__('i18n.backend.support.tenancy.acting_user_context.background_work_could_not_resolve_its_tenant_context'));
            }

            $actingUser = User::query()->find($actingUserId);

            if (!$actingUser instanceof User) {
                throw new AuthorizationException(__('i18n.backend.support.tenancy.acting_user_context.background_work_could_not_resolve_an_active_acting_user'));
            }

            app()->instance('current_tenant', $tenant);
            Context::addHidden('tenant_id', $tenant->getKey());

            Auth::setUser($actingUser);

            $callback($actingUser);
        } finally {
            Auth::forgetGuards();
            app()->forgetInstance('current_tenant');

            Context::forgetHidden('tenant_id');

            if ($hadHiddenTenantId) {
                Context::addHidden('tenant_id', $previousHiddenTenantId);
            }

            if ($previousTenant instanceof Tenant) {
                app()->instance('current_tenant', $previousTenant);
            }

            if ($previousUser instanceof User) {
                Auth::setUser($previousUser);
            }
        }
    }
}
