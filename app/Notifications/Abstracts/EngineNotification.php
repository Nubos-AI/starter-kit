<?php

declare(strict_types=1);

namespace App\Notifications\Abstracts;

use App\Support\Notifications\ChannelPreferenceResolver;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

abstract class EngineNotification extends Notification
{
    abstract public function type(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function toInbox(mixed $notifiable): array;

    public function toMail(mixed $notifiable): MailMessage
    {
        $data = $this->toInbox($notifiable);

        $title = is_string($data['title'] ?? null) ? $data['title'] : __('i18n.backend.notifications.abstracts.engine_notification.notification');
        $body = is_string($data['body'] ?? null) ? $data['body'] : '';

        $message = (new MailMessage)->subject($title);

        if ($body !== '') {
            $message->line($body);
        }

        return $message;
    }

    public function priority(): int
    {
        return 0;
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return app(ChannelPreferenceResolver::class)->channelsFor($notifiable, $this->type());
    }
}
