<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Authorization\InstallActivityTypePermissionsAction;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class InstallActivityTypePermissions extends Command
{
    protected $signature = 'engine:install-activity-type-permissions {tenant : Tenant ID} {role : Role ID}';

    protected $description = 'Install activity type permissions for one role using its existing reminder type grants';

    public function handle(InstallActivityTypePermissionsAction $install): int
    {
        $tenant = Tenant::query()->findOrFail($this->argument('tenant'));
        TenantContext::withTenantId($tenant->id, function () use ($install): void {
            $role = Role::query()->findOrFail($this->argument('role'));
            $install->execute($role);
        });

        $this->info('Aktivitätstyp-Rechte eingerichtet.');

        return self::SUCCESS;
    }
}
