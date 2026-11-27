<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Notifications\Users\EmailAddressChangedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class NotifyPreviousEmailAddressAction
{
    public function execute(User $user, string $previousEmail, string $deliveryKey): void
    {
        $notification = new EmailAddressChangedNotification;
        $notification->id = $deliveryKey;

        Notification::route('mail', $previousEmail)->notify($notification);

        Log::info('The previous email address was told about the confirmed change.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);
    }
}
