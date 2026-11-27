<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Contracts\Mail\SystemMailDispatcherInterface;
use Illuminate\Notifications\Notification;

class TemporalMailChannel
{
    public function __construct(private readonly SystemMailDispatcherInterface $systemMail) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        $this->systemMail->deliverNotification($notifiable, $notification);
    }
}
