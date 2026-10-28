<?php

declare(strict_types=1);

namespace App\Enums\Webhooks;

enum WebhookEventType: string
{
    case RecordCreated = 'record.created';

    case RecordUpdated = 'record.updated';

    case RecordDeleted = 'record.deleted';

    case RecordStageChanged = 'record.stage_changed';

    case RecordRestored = 'record.restored';

    public function label(): string
    {
        return match ($this) {
            self::RecordCreated => __('i18n.backend.enums.webhooks.webhook_event_type.record_created'),
            self::RecordUpdated => __('i18n.backend.enums.webhooks.webhook_event_type.record_changed'),
            self::RecordDeleted => __('i18n.backend.enums.webhooks.webhook_event_type.record_deleted'),
            self::RecordStageChanged => __('i18n.backend.enums.webhooks.webhook_event_type.stage_changed'),
            self::RecordRestored => __('i18n.backend.enums.webhooks.webhook_event_type.record_restored'),
        };
    }
}
