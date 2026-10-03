<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ReminderTask;
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
            'subject' => $this->reminder->subject,
            'dueAt' => $this->reminder->due_at?->toISOString(),
            'recordId' => $this->reminder->record_id,
            'url' => $this->reminder->record_id !== null
                ? route('engine.records.show', ['record' => $this->reminder->record_id])
                : null,
        ];
    }
}
