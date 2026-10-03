<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\ReminderTask;
use App\Models\User;
use App\Support\Reminders\ReminderAuthority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteReminderTasksAction extends BulkDeleteAction
{
    public function __construct(
        private readonly DeleteReminderTaskAction $deleteReminderTask,
        private readonly ReminderAuthority $reminderAuthority,
    ) {}

    /**
     * @return Builder<ReminderTask>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return ReminderTask::query();
    }

    /**
     * @param  ReminderTask  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteReminderTask->execute($model);
    }

    /**
     * @param  ReminderTask  $model
     */
    protected function mayDelete(User $actor, Model $model): bool
    {
        return $this->reminderAuthority->manages($actor, $model);
    }
}
