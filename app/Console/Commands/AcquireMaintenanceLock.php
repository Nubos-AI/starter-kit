<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Maintenance\AcquireMaintenanceLockAction;
use App\Enums\Maintenance\MaintenanceLockReason;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Models\MaintenanceLock;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Console\ActingUserResolver;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AcquireMaintenanceLock extends Command
{
    protected $signature = 'maintenance:lock {tenant : ULID of the tenant} {--as-user= : E-mail address or ULID of the acting user} {--reason= : Reason for the maintenance mode (required, at most 1000 characters)}';

    protected $description = 'Switches a tenant into maintenance mode';

    public function handle(
        AcquireMaintenanceLockAction $action,
        ActingUserResolver $resolver,
        ActingUserContext $actingUserContext,
    ): int {
        $tenantArgument = $this->argument('tenant');
        $tenant = Str::isUlid($tenantArgument) ? Tenant::query()->find($tenantArgument) : null;

        if (!$tenant instanceof Tenant) {
            $this->error('The given tenant was not found.');

            return self::FAILURE;
        }

        $tenantId = (string) $tenant->getKey();
        $asUser = $this->option('as-user');
        $reason = $this->option('reason');
        $actingUserId = null;
        $lock = null;

        try {
            $actor = $resolver->resolve(is_string($asUser) ? $asUser : null);
            $actingUserId = (string) $actor->getKey();

            $actingUserContext->run(
                (string) $actor->tenant_id,
                $actingUserId,
                function (User $actingUser) use ($action, $tenantId, $reason, &$lock): void {
                    $lock = $action->execute($actingUser, $tenantId, MaintenanceLockReason::Manual, [
                        'note' => $reason,
                    ]);
                },
            );
        } catch (AuthorizationException|ValidationException|MissingActingUserException|TenantUnderMaintenanceException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        } catch (Throwable $failure) {
            Log::error('Maintenance lock command failed.', [
                'tenant_id' => $tenantId,
                'acting_user_id' => $actingUserId,
                'exception_class' => $failure::class,
                'exception' => $failure->getMessage(),
            ]);

            $this->error('The maintenance mode could not be switched on; the cause is in the log.');

            return self::FAILURE;
        }

        if ($lock instanceof MaintenanceLock) {
            $this->info("Maintenance mode of tenant {$tenantId} is on (lock {$lock->getKey()}).");
        }

        return self::SUCCESS;
    }
}
