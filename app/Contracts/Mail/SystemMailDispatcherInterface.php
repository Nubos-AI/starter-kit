<?php

declare(strict_types=1);

namespace App\Contracts\Mail;

use App\Exceptions\Mail\SystemMailTooLargeException;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;

interface SystemMailDispatcherInterface
{
    /**
     * @throws SystemMailTooLargeException
     */
    public function deliverNotification(mixed $notifiable, Notification $notification): void;

    /**
     * @param  non-empty-string  $deliveryKey
     *
     * @throws SystemMailTooLargeException
     */
    public function deliverMailable(Mailable $mailable, string $deliveryKey, ?string $tenantId = null): void;
}
