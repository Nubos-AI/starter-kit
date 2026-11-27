<?php

declare(strict_types=1);

namespace App\Actions\Reminders;

use App\Models\ReminderTask;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Support\Notifications\PendingChannelDelivery;
use JsonException;

class DeliverDueReminderChannelAction
{
    public function __construct(private readonly PendingChannelDelivery $channelDelivery) {}

    /**
     * @throws JsonException
     */
    public function execute(string $reminderId, string $channel): void
    {
        $reminder = ReminderTask::query()->with('assignee')->whereKey($reminderId)->firstOrFail();

        if (!$reminder->assignee instanceof User) {
            return;
        }

        $this->channelDelivery->deliverChannel(
            $reminder->assignee,
            new ReminderDueNotification($reminder),
            $channel,
            $reminder->notified_channels,
            ReminderTask::query()->whereKey($reminderId),
        );
    }
}
