<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenancy\DeleteTenantAction;
use App\DTOs\Tenancy\PurgeReport;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DeleteTenant extends Command
{
    protected $signature = 'tenants:delete {--tenant=} {--as-user=} {--confirm-name=}';

    protected $description = 'Löscht einen produktiven Mandanten unwiderruflich';

    public function handle(DeleteTenantAction $action, ActingUserContext $actingUserContext): int
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

        $report = null;

        try {
            $actingUserContext->run(
                (string) $tenant->getKey(),
                (string) $actor->getKey(),
                function (User $actingUser) use ($action, $tenant, &$report): void {
                    $report = $action->execute($tenant, $actingUser);
                },
            );
        } catch (AuthorizationException|InvalidArgumentException|TenantUnderMaintenanceException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $removedRows = $report instanceof PurgeReport ? $report->total() : 0;

        $this->info("Mandant \"{$tenant->name}\" wurde gelöscht — {$removedRows} Zeile(n) entfernt.");

        return self::SUCCESS;
    }
}
