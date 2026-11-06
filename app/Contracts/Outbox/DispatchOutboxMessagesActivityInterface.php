<?php

declare(strict_types=1);

namespace App\Contracts\Outbox;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Outbox.')]
interface DispatchOutboxMessagesActivityInterface
{
    #[ActivityMethod(name: 'dispatchMessages')]
    public function dispatchMessages(): int;
}
