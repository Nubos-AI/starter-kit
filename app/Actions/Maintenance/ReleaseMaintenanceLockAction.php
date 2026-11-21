<?php

declare(strict_types=1);

namespace App\Actions\Maintenance;

use App\Enums\Authorization\RoleAuthority;
use App\Enums\Maintenance\MaintenanceLockRelease;
use App\Enums\Maintenance\MaintenanceLockStatus;
use App\Exceptions\ConfigBundle\MissingActingUserException;
use App\Models\MaintenanceLock;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Console\ActingUserResolver;
use App\Support\Maintenance\MaintenanceScheduleSuspender;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReleaseMaintenanceLockAction
{
    public function __construct(
        private readonly MaintenanceScheduleSuspender $suspender,
        private readonly AdminArtifactAuditor $auditor,
        private readonly ActingUserResolver $actingUsers,
        private readonly TenantBinder $binder,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(
        User $actor,
        MaintenanceLock $lock,
        MaintenanceLockRelease $mode,
        ?string $note = null,
    ): MaintenanceLock {
        if ($lock->status === MaintenanceLockStatus::Released) {
            return $lock;
        }

        return match ($mode) {
            MaintenanceLockRelease::Automatic => $this->releaseAutomatically($actor, $lock, $note),
            MaintenanceLockRelease::Manual => $this->releaseManually($actor, $lock, $note),
            MaintenanceLockRelease::Emergency => $this->releaseThroughEmergencyExit($actor, $lock, $note),
        };
    }

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    private function releaseAutomatically(User $actor, MaintenanceLock $lock, ?string $note): MaintenanceLock
    {
        $this->validateNote($note, false);

        $this->resumeSchedules($lock);

        return $this->markReleased($lock, MaintenanceLockRelease::Automatic, $note, $this->automaticReleaserId($actor, $lock));
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    private function releaseManually(User $actor, MaintenanceLock $lock, ?string $note): MaintenanceLock
    {
        if (!$actor->hasPermission('maintenance.manage') || (string) $actor->tenant_id !== $lock->tenant_id) {
            throw new AuthorizationException(__('i18n.backend.actions.maintenance.release_maintenance_lock_action.you_may_not_end_maintenance_mode_for_this_tenant'));
        }

        $this->validateNote($note, false);

        $this->resumeSchedules($lock);

        return $this->markReleased($lock, MaintenanceLockRelease::Manual, $note, (string) $actor->getKey());
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    private function releaseThroughEmergencyExit(User $actor, MaintenanceLock $lock, ?string $reason): MaintenanceLock
    {
        $fallback = $this->emergencyFallbackFor($actor, $lock);

        $this->validateNote($reason, true);

        $this->resumeSchedules($lock);

        return $this->markReleased(
            $lock,
            MaintenanceLockRelease::Emergency,
            $reason,
            (string) $actor->getKey(),
            [
                'reason' => (string) $reason,
                'fallback' => $fallback,
                'acting_user_id' => (string) $actor->getKey(),
                'acting_user_tenant_id' => (string) $actor->tenant_id,
            ],
        );
    }

    /**
     * @throws ValidationException
     */
    private function validateNote(?string $note, bool $required): void
    {
        Validator::make(
            ['note' => $note],
            ['note' => [$required ? 'required' : 'nullable', 'string', 'max:2000']],
        )->validate();
    }

    /**
     * @throws AuthorizationException
     */
    private function emergencyFallbackFor(User $actor, MaintenanceLock $lock): bool
    {
        $lockedTenantId = $lock->tenant_id;

        if (!$this->carriesSuperAdminAuthority($actor)) {
            $this->refuseEmergency($actor, $lock, 'actor_without_super_admin_authority', __('i18n.backend.actions.maintenance.release_maintenance_lock_action.only_a_super_admin_authority_may_use_the_emergency'));
        }

        if ((string) $actor->tenant_id !== $lockedTenantId) {
            return false;
        }

        if ($this->anotherTenantHasSuperAdmin($lockedTenantId)) {
            $this->refuseEmergency($actor, $lock, 'another_tenant_has_super_admin', __('i18n.backend.actions.maintenance.release_maintenance_lock_action.while_another_tenant_has_a_super_admin_authority_the'));
        }

        return true;
    }

    private function carriesSuperAdminAuthority(User $user): bool
    {
        $tenantId = (string) $user->tenant_id;

        if ($tenantId === '') {
            return false;
        }

        return $this->binder->runIfKnown($tenantId, static function () use ($user): bool {
            $user->forgetResolvedRoles();

            try {
                return $user->hasRoleWithAuthority(RoleAuthority::SuperAdmin);
            } finally {
                $user->forgetResolvedRoles();
            }
        }) === true;
    }

    private function anotherTenantHasSuperAdmin(string $lockedTenantId): bool
    {
        $tenantIds = Role::withoutTenantScope()
            ->where('authority', RoleAuthority::SuperAdmin->value)
            ->where('tenant_id', '!=', $lockedTenantId)
            ->distinct()
            ->pluck('tenant_id')
            ->all();

        if ($tenantIds === []) {
            return false;
        }

        foreach (User::query()->whereIn('tenant_id', $tenantIds)->lazyById(200) as $candidate) {
            if ($this->isAcceptedActingUser($candidate) && $this->carriesSuperAdminAuthority($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function isAcceptedActingUser(User $candidate): bool
    {
        try {
            $this->actingUsers->resolve((string) $candidate->getKey());
        } catch (MissingActingUserException) {
            return false;
        }

        return true;
    }

    /**
     * @throws AuthorizationException
     */
    private function refuseEmergency(User $actor, MaintenanceLock $lock, string $reason, string $message): never
    {
        Log::warning('An emergency maintenance lock release was refused.', [
            'tenant_id' => $lock->tenant_id,
            'lock_id' => (string) $lock->getKey(),
            'acting_user_id' => (string) $actor->getKey(),
            'acting_user_tenant_id' => (string) $actor->tenant_id,
            'reason' => $reason,
        ]);

        throw new AuthorizationException($message);
    }

    /**
     * @throws Throwable
     */
    private function resumeSchedules(MaintenanceLock $lock): void
    {
        $this->suspender->resume($lock->suspended_schedule_ids ?? [], "maintenance-lock:{$lock->getKey()}");
    }

    /**
     * @param  array<string, mixed>|null  $emergency
     *
     * @throws Throwable
     */
    private function markReleased(
        MaintenanceLock $lock,
        MaintenanceLockRelease $mode,
        ?string $note,
        ?string $releasedById,
        ?array $emergency = null,
    ): MaintenanceLock {
        $lockId = (string) $lock->getKey();
        $scheduleIds = $lock->suspended_schedule_ids ?? [];

        return DB::transaction(function () use ($lockId, $mode, $note, $scheduleIds, $releasedById, $emergency): MaintenanceLock {
            $updated = MaintenanceLock::withoutTenantScope()
                ->whereKey($lockId)
                ->where('status', MaintenanceLockStatus::Active)
                ->update([
                    'status' => MaintenanceLockStatus::Released->value,
                    'released_at' => now(),
                    'released_by_id' => $releasedById,
                    'release_mode' => $mode->value,
                    'release_note' => $note,
                ]);

            $fresh = MaintenanceLock::withoutTenantScope()->whereKey($lockId)->firstOrFail();

            if ($updated === 0) {
                return $fresh;
            }

            $tenantId = $fresh->tenant_id;

            $this->auditor->recordEvent($fresh, 'operation.maintenance_released', [
                'mode' => $mode->value,
                'resumed_schedule_ids' => $scheduleIds,
            ], $tenantId);

            if ($emergency !== null) {
                $this->auditor->recordEvent($fresh, 'operation.maintenance_emergency_released', $emergency, $tenantId);

                Log::warning('A maintenance lock was released through the emergency exit.', [
                    'tenant_id' => $tenantId,
                    'lock_id' => $lockId,
                    'acting_user_id' => $emergency['acting_user_id'],
                    'acting_user_tenant_id' => $emergency['acting_user_tenant_id'],
                    'fallback' => $emergency['fallback'],
                ]);
            }

            return $fresh;
        });
    }

    private function automaticReleaserId(User $actor, MaintenanceLock $lock): ?string
    {
        $actorId = (string) $actor->getKey();

        $actorStillExists = User::query()
            ->withoutGlobalScopes()
            ->whereKey($actorId)
            ->exists();

        if ($actorStillExists) {
            return $actorId;
        }

        Log::warning('An automatic maintenance lock release names an acting user that no longer exists.', [
            'tenant_id' => $lock->tenant_id,
            'lock_id' => (string) $lock->getKey(),
        ]);

        return null;
    }
}
