<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ReminderTask;
use App\Models\User;
use App\Notifications\Abstracts\EngineNotification;

class ReminderDueNotification extends EngineNotification
{
    public function __construct(private readonly ReminderTask $reminder) {}

    public function type(): string
    {
        return 'reminder.due';
    }

    /**
     * @return array<string, mixed>
     */
    public function toInbox(mixed $notifiable): array
    {
        return [
            'title' => __('i18n.backend.notifications.reminder_due_notification.title', ['value1' => $this->reminder->subject]),
            'body' => $this->dueBody($notifiable),
            'subject' => $this->reminder->subject,
            'dueAt' => $this->reminder->due_at?->toISOString(),
            'recordId' => $this->reminder->record_id,
            'url' => $this->reminder->record_id !== null
                ? $this->linkTo('engine.records.show', ['record' => $this->reminder->record_id], $notifiable)
                : null,
        ];
    }

    private function dueBody(mixed $notifiable): string
    {
        if ($this->reminder->due_at === null) {
            return __('i18n.backend.notifications.reminder_due_notification.due_now');
        }

        $timezone = ($notifiable instanceof User ? $notifiable->timezone : null) ?? (string) config('app.timezone');

        return __('i18n.backend.notifications.reminder_due_notification.due_at', [
            'value1' => $this->reminder->due_at->copy()->setTimezone($timezone)->format('d.m.Y, H:i'),
        ]);
    }
}
