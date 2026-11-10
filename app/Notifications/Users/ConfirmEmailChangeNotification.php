<?php

declare(strict_types=1);

namespace App\Notifications\Users;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmEmailChangeNotification extends Notification
{
    public function __construct(
        public string $token,
        public CarbonInterface $expiresAt,
    ) {
        $this->locale((string) config('app.user_locale'));
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('i18n.backend.notifications.users.confirm_email_change_notification.confirm_your_new_email_address'))
            ->line(__('i18n.backend.notifications.users.confirm_email_change_notification.this_address_should_become_the_new_address_of_an_account'))
            ->action(__('i18n.backend.notifications.users.confirm_email_change_notification.confirm_new_email_address'), route('email-change.show', ['token' => $this->token]))
            ->line(__('i18n.backend.notifications.users.confirm_email_change_notification.the_link_is_valid_until', ['value1' => $this->expiresAt->translatedFormat('d.m.Y \u\m H:i \U\h\r')]))
            ->line(__('i18n.backend.notifications.users.confirm_email_change_notification.if_you_did_not_request_this_change'));
    }
}
