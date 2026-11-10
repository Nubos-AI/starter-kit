<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class NotificationDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  iterable<int, object>  $entries
     */
    public function __construct(public iterable $entries) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('i18n.backend.mail.notification_digest_mail.your_notification_digest'),
        );
    }

    public function content(): Content
    {
        $items = Collection::make($this->entries)
            ->map(function (object $entry): string {
                $title = data_get($entry, 'data.title', data_get($entry, 'type', __('i18n.backend.mail.notification_digest_mail.notification')));

                return '<li>'.e((string) $title).'</li>';
            })
            ->implode('');

        return new Content(
            htmlString: '<ul>'.$items.'</ul>',
        );
    }
}
