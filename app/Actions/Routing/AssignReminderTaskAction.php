<?php

declare(strict_types=1);

namespace App\Actions\Routing;

use App\Actions\Reminders\UpdateReminderTaskAction;
use App\DTOs\Routing\AssignmentOutcome;
use App\DTOs\Routing\AssignmentRequest;
use App\Enums\Routing\AssignmentFailureReason;
use App\Enums\Routing\ReminderAssignmentScope;
use App\Models\CustomRecord;
use App\Models\ReminderTask;
use App\Models\User;
use App\Support\Routing\AssignmentSelector;
use Throwable;

class AssignReminderTaskAction
{
    public function __construct(
        private readonly AssignmentSelector $assignmentSelector,
        private readonly UpdateReminderTaskAction $updateReminderTaskAction,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(CustomRecord $record, AssignmentRequest $request): AssignmentOutcome
    {
        $tasks = $this->openTasks($record, $request->reminderScope);

        if ($tasks === []) {
            return AssignmentOutcome::failed(AssignmentFailureReason::NoOpenReminderTask);
        }

        $outcome = $this->assignmentSelector->select($record, $request);
        $candidate = $outcome->user;

        if (!$candidate instanceof User) {
            return $outcome;
        }

        foreach ($tasks as $task) {
            $this->updateReminderTaskAction->execute($task, [
                'assignee_id' => (string) $candidate->getKey(),
            ]);
        }

        return $outcome;
    }

    /**
     * @return list<ReminderTask>
     */
    private function openTasks(CustomRecord $record, ReminderAssignmentScope $scope): array
    {
        $query = ReminderTask::query()
            ->where('record_id', $record->getKey())
            ->whereNull('done_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($scope === ReminderAssignmentScope::LatestOpen) {
            $query->limit(1);
        }

        return array_values($query->get()->all());
    }
}
