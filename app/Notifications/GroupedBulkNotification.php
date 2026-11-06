<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Abstracts\EngineNotification;

class GroupedBulkNotification extends EngineNotification
{
    public function __construct(
        private readonly int $affectedCount,
        private readonly string $objectTypeSlug,
    ) {}

    public function type(): string
    {
        return 'bulk.grouped';
    }

    /**
     * @return array<string, mixed>
     */
    public function toInbox(mixed $notifiable): array
    {
        return [
            'affectedCount' => $this->affectedCount,
            'objectTypeSlug' => $this->objectTypeSlug,
            'body' => __('i18n.backend.notifications.grouped_bulk_notification.records_updated', ['value1' => $this->affectedCount]),
        ];
    }
}
