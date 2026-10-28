<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Enums\Export\ExportJobStatus;
use App\Models\ExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ExportCompletedNotification;
use Illuminate\Support\Facades\Context;

class ExportFinalizer
{
    public function finalize(string $tenantId, string $userId, string $exportJobId): void
    {
        $tenant = Tenant::query()->find($tenantId);

        if (!$tenant instanceof Tenant) {
            return;
        }

        $previousTenant = app()->bound('current_tenant') ? app('current_tenant') : null;
        app()->instance('current_tenant', $tenant);
        Context::addHidden('tenant_id', $tenantId);

        try {
            $exportJob = ExportJob::query()->whereKey($exportJobId)->first();

            if (!$exportJob instanceof ExportJob) {
                return;
            }

            $completed = $exportJob->result_path !== null;

            $exportJob->forceFill([
                'status' => $completed ? ExportJobStatus::Completed : ExportJobStatus::Failed,
                'finished_at' => now(),
            ])->save();

            if (!$completed) {
                return;
            }

            $user = User::query()->whereKey($userId)->first();

            if ($user instanceof User) {
                $user->notify(new ExportCompletedNotification($exportJob));
            }
        } finally {
            app()->forgetInstance('current_tenant');

            if ($previousTenant instanceof Tenant) {
                app()->instance('current_tenant', $previousTenant);
            }
        }
    }
}
