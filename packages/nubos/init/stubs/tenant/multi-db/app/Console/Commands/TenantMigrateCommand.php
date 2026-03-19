<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantDatabaseManager;
use Illuminate\Console\Command;

class TenantMigrateCommand extends Command
{
    protected $signature = 'tenant:migrate {--tenant= : Specific tenant ID to migrate}';
    protected $description = 'Run migrations for all tenant databases (or a specific one)';

    public function handle(TenantDatabaseManager $manager): int
    {
        $tenantId = $this->option('tenant');

        if (is_string($tenantId)) {
            $tenant = Tenant::query()->findOrFail($tenantId);

            /** @var Tenant $tenant */
            $manager->migrate($tenant);
            $this->info("Migrated tenant: {$tenant->name}");

            return self::SUCCESS;
        }

        $manager->migrateAll();
        $this->info('All tenant databases migrated.');

        return self::SUCCESS;
    }
}
