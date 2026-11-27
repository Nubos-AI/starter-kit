<?php

declare(strict_types=1);

namespace App\DTOs\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class SystemMailEnvelope
{
    use SerializesModels;

    public function __construct(
        public string $locale,
        public mixed $notifiable = null,
        public ?Notification $notification = null,
        public ?Mailable $mailable = null,
    ) {}
}
