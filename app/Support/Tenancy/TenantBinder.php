<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\Context;

class TenantBinder
{
    /**
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $work
     * @return TReturn
     */
    public function run(string $tenantId, callable $work): mixed
    {
        return $this->runWith(Tenant::query()->whereKey($tenantId)->firstOrFail(), $work);
    }

    /**
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $work
     * @return TReturn|null
     */
    public function runIfKnown(string $tenantId, callable $work): mixed
    {
        $tenant = Tenant::query()->find($tenantId);

        return $tenant instanceof Tenant ? $this->runWith($tenant, $work) : null;
    }

    /**
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $work
     * @return TReturn
     */
    public function runWith(Tenant $tenant, callable $work): mixed
    {
        $previous = app()->bound('current_tenant') ? app('current_tenant') : null;
        $previousContext = Context::getHidden('tenant_id');

        app()->instance('current_tenant', $tenant);
        Context::addHidden('tenant_id', (string) $tenant->getKey());

        try {
            return $work($tenant);
        } finally {
            app()->forgetInstance('current_tenant');

            if ($previous instanceof Tenant) {
                app()->instance('current_tenant', $previous);
            }

            Context::forgetHidden('tenant_id');

            if (is_string($previousContext)) {
                Context::addHidden('tenant_id', $previousContext);
            }
        }
    }
}
