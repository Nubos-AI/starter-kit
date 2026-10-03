<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ReminderTask;
use App\Support\Watchers\WatcherAutoSubscriber;

class ReminderTaskWatcherObserver
{
    public function __construct(private readonly WatcherAutoSubscriber $watcherAutoSubscriber) {}

    public function created(ReminderTask $task): void
    {
        if ($task->record_id === null) {
            return;
        }

        $this->watcherAutoSubscriber->onActivityAdded($task->record_id, $task->creator_id);

        if ($task->assignee_id !== null) {
            $this->watcherAutoSubscriber->onActivityAdded($task->record_id, $task->assignee_id);
        }
    }
}
