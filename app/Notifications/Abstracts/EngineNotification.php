<?php

declare(strict_types=1);

namespace App\Notifications\Abstracts;

use App\Support\Notifications\ChannelPreferenceResolver;
use App\Support\Notifications\RecipientLinkBuilder;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;
use Stringable;

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
        $url = $data['url'] ?? null;

        $message = (new MailMessage)->subject($title);

        if ($body !== '') {
            $message->line($body);
        }

        if (is_string($url) && $url !== '') {
            $message->action(__('i18n.backend.notifications.abstracts.engine_notification.open'), $url);
        }

        return $message;
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        $mail = $this->toMail($notifiable);
        $url = $this->toInbox($notifiable)['url'] ?? null;

        $message = (new WebPushMessage)
            ->title((string) $mail->subject)
            ->body(implode(' ', array_map($this->plainText(...), $mail->introLines)));

        if (is_string($url) && $url !== '') {
            $message->data(['url' => $url]);
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

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function linkTo(string $route, array $parameters, mixed $notifiable): ?string
    {
        return app(RecipientLinkBuilder::class)->route($route, $parameters, $notifiable);
    }

    private function plainText(mixed $line): string
    {
        if ($line instanceof Htmlable) {
            return trim(html_entity_decode(strip_tags($line->toHtml()), ENT_QUOTES | ENT_HTML5));
        }

        return is_scalar($line) || $line instanceof Stringable ? (string) $line : '';
    }
}
