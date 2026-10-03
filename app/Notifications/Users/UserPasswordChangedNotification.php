<?php

declare(strict_types=1);

namespace App\Notifications\Users;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserPasswordChangedNotification extends Notification
{
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
            ->subject(__('i18n.backend.notifications.users.user_password_changed_notification.your_password_has_been_changed'))
            ->line(__('i18n.backend.notifications.users.user_password_changed_notification.a_new_password_has_been_set_for_your_account'))
            ->line(__('i18n.backend.notifications.users.user_password_changed_notification.you_have_been_signed_out_on_all_devices_if'));
    }
}
