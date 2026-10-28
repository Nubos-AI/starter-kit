<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Maintenance\ReleaseMaintenanceLockAction;
use App\Enums\Maintenance\MaintenanceLockRelease;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Models\MaintenanceLock;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Console\ActingUserResolver;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReleaseMaintenanceLock extends Command
{
    protected $signature = 'maintenance:unlock {tenant : ULID des Mandanten} {--as-user= : E-Mail-Adresse oder ULID des Handelnden} {--note= : Begründung}';

    protected $description = 'Beendet den Wartungsmodus eines Mandanten durch eine berechtigte Person';

    public function handle(
        MaintenanceLockRegistry $registry,
        ReleaseMaintenanceLockAction $action,
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
        $released = null;
        $actingUserId = null;

        try {
            $actor = $resolver->resolve($this->textOption('as-user'));
            $actingUserId = (string) $actor->getKey();

            $actingUserContext->run(
                (string) $actor->tenant_id,
                $actingUserId,
                function (User $actingUser) use ($registry, $action, $tenantId, &$released): void {
                    $lock = $registry->activeFor($tenantId);

                    if ($lock instanceof MaintenanceLock) {
                        $released = $action->execute(
                            $actingUser,
                            $lock,
                            $this->releaseMode(),
                            $this->justification(),
                        );
                    }
                },
            );
        } catch (AuthorizationException|ValidationException|MissingActingUserException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        } catch (Throwable $failure) {
            Log::error($this->failureLogMessage(), [
                'tenant_id' => $tenantId,
                'acting_user_id' => $actingUserId,
                'exception_class' => $failure::class,
                'exception' => $failure->getMessage(),
            ]);

            $this->error('Die Wartungssperre konnte nicht aufgehoben werden; die Ursache steht im Protokoll. Ein erneuter Versuch ist möglich.');

            return self::FAILURE;
        }

        if (!$released instanceof MaintenanceLock) {
            $this->info("Für den Mandanten {$tenantId} besteht keine aktive Wartungssperre.");

            return self::SUCCESS;
        }

        $this->reportReleased($released, $tenantId);

        return self::SUCCESS;
    }

    protected function releaseMode(): MaintenanceLockRelease
    {
        return MaintenanceLockRelease::Manual;
    }

    protected function justification(): ?string
    {
        return $this->textOption('note');
    }

    protected function failureLogMessage(): string
    {
        return 'Manual maintenance lock release command failed.';
    }

    protected function reportReleased(MaintenanceLock $released, string $tenantId): void
    {
        $this->info("Die Wartungssperre {$released->getKey()} des Mandanten {$tenantId} wurde aufgehoben.");
    }

    protected function textOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }
}
