<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\DTOs\Reminders\DueReminderCase;
use App\Models\ReminderTask;
use App\Support\Maintenance\MaintenanceLockRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;

class ListDueRemindersAction
{
    public function __construct(
        private readonly MaintenanceLockRegistry $maintenanceLocks,
        private readonly Repository $config,
    ) {}

    /**
     * @return list<DueReminderCase>
     */
    public function execute(CarbonImmutable $now): array
    {
        $lockedTenantIds = $this->maintenanceLocks->lockedTenantIds();

        return array_values(ReminderTask::withoutTenantScope()
            ->whereNull('done_at')
            ->whereNull('notified_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->whereHas('assignee', static fn (Builder $query): Builder => $query->active())
            ->when($lockedTenantIds !== [], static fn (Builder $query): Builder => $query->whereNotIn('reminder_tasks.tenant_id', $lockedTenantIds))
            ->orderBy('due_at')
            ->limit(max(1, (int) $this->config->get('record-processing.scans.max_cases_per_run')))
            ->get(['id', 'tenant_id'])
            ->map(static fn (ReminderTask $reminder): DueReminderCase => new DueReminderCase($reminder->tenant_id, (string) $reminder->getKey()))
            ->all());
    }
}
