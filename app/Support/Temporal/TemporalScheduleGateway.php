<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use App\Contracts\Modules\OutboundGuardInterface;
use App\Enums\Notifications\SuppressedChannel;
use App\Enums\Temporal\ScheduleEgress;
use Temporal\Client\Schedule\Schedule;
use Temporal\Client\ScheduleClientInterface;
use Temporal\Workflow\WorkflowExecution;

class TemporalScheduleGateway
{
    public function __construct(
        private readonly ScheduleClientInterface $client,
        private readonly OutboundGuardInterface $egressGuard,
    ) {}

    /**
     * @param  non-empty-string  $scheduleId
     */
    public function exists(string $scheduleId): bool
    {
        foreach ($this->client->listSchedules() as $entry) {
            if ($entry->scheduleId === $scheduleId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  non-empty-string  $scheduleId
     */
    public function create(string $scheduleId, Schedule $schedule, ScheduleEgress $egress): void
    {
        if ($this->isOutboundBlocked($scheduleId, $egress)) {
            return;
        }

        $this->client->createSchedule($schedule, null, $scheduleId);
    }

    /**
     * @param  non-empty-string  $scheduleId
     */
    public function update(string $scheduleId, Schedule $schedule, ScheduleEgress $egress): void
    {
        if ($this->isOutboundBlocked($scheduleId, $egress)) {
            return;
        }

        $this->client->getHandle($scheduleId)->update($schedule);
    }

    /**
     * @param  non-empty-string  $scheduleId
     */
    public function pause(string $scheduleId, string $note): void
    {
        $this->client->getHandle($scheduleId)->pause($note);
    }

    /**
     * @param  non-empty-string  $scheduleId
     */
    public function unpause(string $scheduleId, string $note): void
    {
        $this->client->getHandle($scheduleId)->unpause($note);
    }

    /**
     * @return array<string, bool>
     */
    public function pauseStates(): array
    {
        $states = [];

        foreach ($this->client->listSchedules() as $entry) {
            $states[$entry->scheduleId] = $entry->info->paused;
        }

        return $states;
    }

    /**
     * @param  non-empty-string  $scheduleId
     * @return list<WorkflowExecution>
     */
    public function remove(string $scheduleId): array
    {
        if (!$this->exists($scheduleId)) {
            return [];
        }

        $handle = $this->client->getHandle($scheduleId);
        $this->pause($scheduleId, __('i18n.backend.support.temporal.temporal_schedule_gateway.the_owning_module_is_being_uninstalled'));
        $info = $handle->describe()->info;
        $executions = [];
        foreach ($info->runningWorkflows as $execution) {
            $executions[] = $execution;
        }
        foreach ($info->recentActions as $action) {
            $executions[] = $action->startWorkflowResult;
        }
        $handle->delete();

        return $executions;
    }

    private function isOutboundBlocked(string $scheduleId, ScheduleEgress $egress): bool
    {
        if ($egress !== ScheduleEgress::External) {
            return false;
        }

        return $this->egressGuard->suppressIfBlocked(
            SuppressedChannel::Schedule,
            __('i18n.backend.support.temporal.temporal_schedule_gateway.an_installed_module_blocks_outbound_delivery_outward_facing_schedules'),
            $scheduleId,
        );
    }
}
