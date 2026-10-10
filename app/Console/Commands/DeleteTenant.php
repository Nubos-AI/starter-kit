<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenancy\DeleteTenantAction;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DeleteTenant extends Command
{
    protected $signature = 'tenants:delete {--tenant=} {--as-user=} {--confirm-name=}';

    protected $description = 'Löscht einen produktiven Mandanten unwiderruflich';

    public function handle(DeleteTenantAction $action): int
    {
        $tenantOption = $this->option('tenant');

        if (!is_string($tenantOption) || $tenantOption === '') {
            $this->error('Die Option --tenant ist erforderlich.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->find($tenantOption);

        if (!$tenant instanceof Tenant) {
            $this->error('Der angegebene Mandant wurde nicht gefunden.');

            return self::FAILURE;
        }

        $userOption = $this->option('as-user');

        if (!is_string($userOption) || $userOption === '') {
            $this->error('Die Option --as-user ist erforderlich.');

            return self::FAILURE;
        }

        $actor = User::query()->find($userOption);

        if (!$actor instanceof User || (string) $actor->tenant_id !== (string) $tenant->getKey()) {
            $this->error('Der angegebene Handelnde ist unbekannt oder gehört nicht zu diesem Mandanten.');

            return self::FAILURE;
        }

        $confirmName = $this->option('confirm-name');

        if (!is_string($confirmName) || $confirmName === '' || $confirmName !== $tenant->name) {
            $this->error('Die Option --confirm-name muss dem Namen des Mandanten wörtlich entsprechen.');

            return self::FAILURE;
        }

        try {
            $report = $action->execute($tenant, $actor);
        } catch (AuthorizationException|InvalidArgumentException|TenantUnderMaintenanceException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Mandant \"{$tenant->name}\" wurde gelöscht — {$report->total()} Zeile(n) entfernt.");

        return self::SUCCESS;
    }
}
