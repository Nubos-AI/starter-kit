<?php

declare(strict_types=1);

namespace App\Actions\Tenants;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

class ConfigureTenantDatabaseAction
{
    public function execute(Tenant $tenant): void
    {
        $tenant->configureDatabaseConnection();

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }
}
