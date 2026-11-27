<?php

declare(strict_types=1);

namespace App\Contracts\Users;

use App\DTOs\Users\ChangeUserPasswordData;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'users.')]
interface ChangeUserPasswordActivityInterface
{
    #[ActivityMethod(name: 'setUserPassword')]
    public function setUserPassword(ChangeUserPasswordData $input): void;

    #[ActivityMethod(name: 'notifyPasswordChanged')]
    public function notifyPasswordChanged(string $tenantId, string $userId, string $deliveryKey): void;
}
