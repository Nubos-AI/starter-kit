<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\NotificationInbox;
use App\Notifications\Abstracts\EngineNotification;

class InboxChannel
{
    public function send(mixed $notifiable, EngineNotification $notification): void
    {
        NotificationInbox::query()->create([
            'tenant_id' => $notifiable->tenant_id,
            'user_id' => $notifiable->getKey(),
            'type' => $notification->type(),
            'data' => $notification->toInbox($notifiable),
            'priority' => $notification->priority(),
        ]);
    }
}
