<?php

declare(strict_types=1);

namespace App\Notifications\Users;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailAddressChangedNotification extends Notification
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
            ->subject(__('i18n.backend.notifications.users.email_address_changed_notification.the_email_address_of_your_account_has_been_changed'))
            ->line(__('i18n.backend.notifications.users.email_address_changed_notification.this_address_no_longer_belongs_to_your_account'))
            ->line(__('i18n.backend.notifications.users.email_address_changed_notification.if_you_did_not_request_this_change'));
    }
}
