<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Notifications\Users\UserPasswordChangedNotification;

class NotifyUserPasswordChangedAction
{
    public function execute(User $user, string $deliveryKey): void
    {
        $notification = new UserPasswordChangedNotification;
        $notification->id = $deliveryKey;

        $user->notify($notification);
    }
}
