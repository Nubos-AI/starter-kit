<?php

declare(strict_types=1);

namespace App\Traits\Temporal;

use App\Enums\Temporal\ScheduleEgress;
use Carbon\CarbonInterval;
use Temporal\Client\Schedule\Action\StartWorkflowAction;
use Temporal\Client\Schedule\Policy\ScheduleOverlapPolicy;
use Temporal\Client\Schedule\Policy\SchedulePolicies;
use Temporal\Client\Schedule\Schedule;
use Temporal\Client\Schedule\Spec\ScheduleSpec;

trait RegistersTemporalSchedules
{
    private int $fallbackExecutionTimeoutSeconds = 3600;

    abstract private function executionTimeoutConfigKey(): string;

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
                    ->withWorkflowId("{$scheduleId}-run")
                    ->withTaskQueue((string) config('temporal.queue'))
                    ->withWorkflowExecutionTimeout(CarbonInterval::seconds($this->executionTimeoutSeconds($scheduleId))),
            )
            ->withPolicies(SchedulePolicies::new()->withOverlapPolicy(ScheduleOverlapPolicy::Skip));

        if ($this->gateway->exists($scheduleId)) {
            $this->gateway->update($scheduleId, $schedule, ScheduleEgress::Internal);

            return;
        }

        $this->gateway->create($scheduleId, $schedule, ScheduleEgress::Internal);
    }

    private function executionTimeoutSeconds(string $scheduleId): int
    {
        $key = $this->executionTimeoutConfigKey();
        $seconds = (int) config(
            "{$key}.execution_timeouts.{$scheduleId}",
            config("{$key}.execution_timeout_seconds", config('temporal.schedules.execution_timeout_seconds')),
        );

        return $seconds > 0 ? $seconds : $this->fallbackExecutionTimeoutSeconds;
    }
}
