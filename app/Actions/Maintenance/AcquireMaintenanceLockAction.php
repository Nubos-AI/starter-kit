<?php

declare(strict_types=1);

namespace App\Actions\Maintenance;

use App\Enums\Maintenance\MaintenanceLockReason;
use App\Enums\Maintenance\MaintenanceLockRelease;
use App\Enums\Maintenance\MaintenanceLockStatus;
use App\Exceptions\Maintenance\TenantUnderMaintenanceException;
use App\Models\MaintenanceLock;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Maintenance\MaintenanceScheduleSuspender;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AcquireMaintenanceLockAction
{
    public function __construct(
        private readonly MaintenanceScheduleSuspender $suspender,
        private readonly AdminArtifactAuditor $auditor,
        private readonly ReleaseMaintenanceLockAction $releaseAction,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws TenantUnderMaintenanceException
     * @throws Throwable
     */
    public function execute(User $actor, string $tenantId, MaintenanceLockReason $reason, array $input): MaintenanceLock
    {
        if (!$actor->hasPermission('maintenance.manage')) {
            throw new AuthorizationException(__('i18n.backend.actions.maintenance.acquire_maintenance_lock_action.you_do_not_have_permission_to_enable_maintenance_mode'));
        }

        $validated = Validator::make(
            [...$input, 'tenant_id' => $tenantId],
            [
                'tenant_id' => [
                    'bail',
                    'required',
                    'string',
                    'ulid',
                    Rule::exists('tenants', 'id')->whereNull('deleted_at'),
                ],
                'note' => ['required', 'string', 'max:1000'],
            ],
            [
                'note.required' => __('i18n.backend.actions.maintenance.acquire_maintenance_lock_action.please_provide_a_reason_for_maintenance_mode'),
                'note.max' => __('i18n.backend.actions.maintenance.acquire_maintenance_lock_action.the_reason_must_not_exceed_1000_characters'),
            ],
        )->validate();

        if ((string) $actor->tenant_id !== $tenantId) {
            throw new AuthorizationException(__('i18n.backend.actions.maintenance.acquire_maintenance_lock_action.the_acting_user_does_not_belong_to_the_tenant'));
        }

        $note = (string) $validated['note'];
        $scheduleIds = $this->suspender->suspendableIdsFor($tenantId);

        try {
            $lock = DB::transaction(function () use ($actor, $tenantId, $reason, $note, $scheduleIds): MaintenanceLock {
                $lock = MaintenanceLock::query()->create([
                    'tenant_id' => $tenantId,
                    'acquired_by_id' => (string) $actor->getKey(),
                    'reason' => $reason,
                    'status' => MaintenanceLockStatus::Active,
                    'suspended_schedule_ids' => $scheduleIds,
                    'note' => $note,
                    'acquired_at' => now(),
                ]);

                $this->auditor->recordEvent($lock, 'operation.maintenance_locked', [
                    'reason' => $reason->value,
                    'note' => $note,
                    'suspended_schedule_ids' => $scheduleIds,
                ], $tenantId);

                return $lock;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw TenantUnderMaintenanceException::alreadyLocked($tenantId, $exception);
        }

        $lockId = (string) $lock->getKey();

        try {
            $this->suspender->suspend($scheduleIds, "maintenance-lock:{$lockId}");
        } catch (Throwable $exception) {
            $this->releaseAfterFailedSuspension($actor, $lock);

            throw $exception;
        }

        return $lock;
    }

    private function releaseAfterFailedSuspension(User $actor, MaintenanceLock $lock): void
    {
        try {
            $this->releaseAction->execute($actor, $lock, MaintenanceLockRelease::Automatic, __('i18n.backend.actions.maintenance.acquire_maintenance_lock_action.schedule_suspension_failed'));
        } catch (Throwable $releaseFailure) {
            Log::error('A maintenance lock could not be released after its schedule suspension failed.', [
                'tenant_id' => $lock->tenant_id,
                'lock_id' => (string) $lock->getKey(),
                'exception' => $releaseFailure->getMessage(),
            ]);
        }
    }
}
