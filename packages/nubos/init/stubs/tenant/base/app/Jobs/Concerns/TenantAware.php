<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait TenantAware
{
    public ?string $tenantId = null;

    /**
     * @return array<int, callable>
     */
    public function middleware(): array
    {
        return [
            function (object $job, callable $next): void {
                if ($this->tenantId === null) {
                    throw new RuntimeException('TenantAware: tenantId not set. Call captureTenantContext() in your job constructor.');
                }

                $tenant = Tenant::query()->findOrFail($this->tenantId);
                app()->instance('currentTenant', $tenant);

                if (method_exists($tenant, 'configureDatabaseConnection')) {
                    $tenant->configureDatabaseConnection();
                }

                $next($job);

                app()->forgetInstance('currentTenant');

                if (method_exists($tenant, 'configureDatabaseConnection')) {
                    DB::purge('tenant');
                }
            },
        ];
    }

    protected function captureTenantContext(): void
    {
        if (app()->bound('currentTenant')) {
            /** @var Tenant $tenant */
            $tenant = app('currentTenant');
            $this->tenantId = $tenant->id;
        }
    }
}
