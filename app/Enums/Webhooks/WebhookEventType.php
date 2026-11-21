<?php

declare(strict_types=1);

namespace App\Enums\Webhooks;

enum WebhookEventType: string
{
    case RecordCreated = 'record.created';

    case RecordUpdated = 'record.updated';

    case RecordDeleted = 'record.deleted';

    case RecordRestored = 'record.restored';

    public function label(): string
    {
        return match ($this) {
            self::RecordCreated => __('i18n.backend.enums.webhooks.webhook_event_type.record_created'),
            self::RecordUpdated => __('i18n.backend.enums.webhooks.webhook_event_type.record_changed'),
            self::RecordDeleted => __('i18n.backend.enums.webhooks.webhook_event_type.record_deleted'),
            self::RecordRestored => __('i18n.backend.enums.webhooks.webhook_event_type.record_restored'),
        };
    }

    public static function valueOf(self|string $type): string
    {
        return $type instanceof self ? $type->value : $type;
    }

    public static function labelOf(string $type): string
    {
        $coreType = self::tryFrom($type);

        if ($coreType instanceof self) {
            return $coreType->label();
        }

        $moduleLabel = config('webhooks.event_labels', [])[$type] ?? null;

        return is_string($moduleLabel) ? __($moduleLabel) : $type;
    }
}
