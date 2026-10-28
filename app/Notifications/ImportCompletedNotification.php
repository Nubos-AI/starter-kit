<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ImportJob;
use App\Notifications\Abstracts\EngineNotification;
use Illuminate\Notifications\Messages\MailMessage;

class ImportCompletedNotification extends EngineNotification
{
    public function __construct(private readonly ImportJob $importJob) {}

    public function type(): string
    {
        return 'import.completed';
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('i18n.backend.notifications.import_completed_notification.import_complete'))
            ->line(__('i18n.backend.notifications.import_completed_notification.the_import_of_file_is_complete', ['value1' => $this->importJob->original_filename]))
            ->line("Angelegt: {$this->importJob->created_count}, aktualisiert: {$this->importJob->updated_count}, fehlgeschlagen: {$this->importJob->error_count}.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toInbox(mixed $notifiable): array
    {
        return [
            'subject' => $this->importJob->original_filename,
            'status' => $this->importJob->status->value,
            'createdCount' => $this->importJob->created_count,
            'updatedCount' => $this->importJob->updated_count,
            'errorCount' => $this->importJob->error_count,
            'url' => $this->importJob->error_report_path !== null
                ? route('engine.import.error-report', [
                    'objectType' => $this->importJob->objectType->slug,
                    'importJob' => $this->importJob->getKey(),
                ])
                : null,
        ];
    }
}
