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
    protected $signature = 'maintenance:lock {tenant : ULID des Mandanten} {--as-user= : E-Mail-Adresse oder ULID des Handelnden} {--reason= : Grund für den Wartungsmodus (Pflicht, höchstens 1000 Zeichen)}';

    protected $description = 'Schaltet den Wartungsmodus eines Mandanten ein';

    public function handle(
        AcquireMaintenanceLockAction $action,
        ActingUserResolver $resolver,
        ActingUserContext $actingUserContext,
    ): int {
        $tenantArgument = $this->argument('tenant');
        $tenant = Str::isUlid($tenantArgument) ? Tenant::query()->find($tenantArgument) : null;

        if (!$tenant instanceof Tenant) {
            $this->error('Der angegebene Mandant wurde nicht gefunden.');

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

            $this->error('Der Wartungsmodus konnte nicht eingeschaltet werden; die Ursache steht im Protokoll.');

            return self::FAILURE;
        }

        if ($lock instanceof MaintenanceLock) {
            $this->info("Der Wartungsmodus des Mandanten {$tenantId} ist eingeschaltet (Sperre {$lock->getKey()}).");
        }

        return self::SUCCESS;
    }
}
