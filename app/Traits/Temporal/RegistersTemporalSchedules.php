<?php

declare(strict_types=1);

namespace App\Traits\Temporal;

use App\Enums\Temporal\ScheduleEgress;
use Temporal\Client\Schedule\Action\StartWorkflowAction;
use Temporal\Client\Schedule\Policy\ScheduleOverlapPolicy;
use Temporal\Client\Schedule\Policy\SchedulePolicies;
use Temporal\Client\Schedule\Schedule;
use Temporal\Client\Schedule\Spec\ScheduleSpec;

trait RegistersTemporalSchedules
{
    /**
     * @param  non-empty-string  $scheduleId
     */
    private function upsertIntervalSchedule(string $scheduleId, string $workflowType, int $intervalSeconds): void
    {
        $this->upsertSchedule($scheduleId, $workflowType, ScheduleSpec::new()->withIntervalList($intervalSeconds));
    }

    /**
     * @param  non-empty-string  $scheduleId
     */
    private function upsertCronSchedule(string $scheduleId, string $workflowType, string $cron, string $timezone): void
    {
        $this->upsertSchedule(
            $scheduleId,
            $workflowType,
            ScheduleSpec::new()->withCronStringList($cron)->withTimezoneName($timezone),
        );
    }

    /**
     * @param  non-empty-string  $scheduleId
     */
    private function upsertSchedule(string $scheduleId, string $workflowType, ScheduleSpec $spec): void
    {
        $schedule = Schedule::new()
            ->withSpec($spec)
            ->withAction(
                StartWorkflowAction::new($workflowType)
                    ->withWorkflowId($scheduleId.'-run')
                    ->withTaskQueue((string) config('temporal.queue')),
            )
            ->withPolicies(SchedulePolicies::new()->withOverlapPolicy(ScheduleOverlapPolicy::Skip));

        if ($this->gateway->exists($scheduleId)) {
            $this->gateway->update($scheduleId, $schedule, ScheduleEgress::Internal);

            return;
        }

        $this->gateway->create($scheduleId, $schedule, ScheduleEgress::Internal);
    }
}
