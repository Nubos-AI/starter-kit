<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ExportJob;
use App\Notifications\Abstracts\EngineNotification;
use Illuminate\Notifications\Messages\MailMessage;

class ExportCompletedNotification extends EngineNotification
{
    public function __construct(private readonly ExportJob $exportJob) {}

    public function type(): string
    {
        return 'export.completed';
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('i18n.backend.notifications.export_completed_notification.export_complete'))
            ->line(__('i18n.backend.notifications.export_completed_notification.the_export_for_is_complete', ['value1' => $this->exportJob->objectType->name]))
            ->line(__('i18n.backend.notifications.export_completed_notification.records', ['value1' => $this->exportJob->row_count]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toInbox(mixed $notifiable): array
    {
        return [
            'subject' => $this->exportJob->objectType->name,
            'status' => $this->exportJob->status->value,
            'format' => $this->exportJob->format->value,
            'rowCount' => $this->exportJob->row_count,
            'url' => $this->exportJob->result_path !== null
                ? route('engine.export.download', [
                    'objectType' => $this->exportJob->objectType->slug,
                    'exportJob' => $this->exportJob->getKey(),
                ])
                : null,
        ];
    }
}
