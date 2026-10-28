<?php

declare(strict_types=1);

namespace App\Notifications\Users;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification
{
    public function __construct(
        public string $token,
        public string $invitedBy,
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
            ->subject(__('i18n.backend.notifications.users.user_invitation_notification.you_have_been_invited'))
            ->line(__('i18n.backend.notifications.users.user_invitation_notification.has_invited_you_to_collaborate', ['value1' => $this->invitedBy]))
            ->action(__('i18n.backend.notifications.users.user_invitation_notification.set_up_account'), route('invitations.show', ['token' => $this->token]))
            ->line(__('i18n.backend.notifications.users.user_invitation_notification.the_link_is_valid_until', ['value1' => $this->expiresAt->translatedFormat('d.m.Y \u\m H:i \U\h\r')]));
    }
}
