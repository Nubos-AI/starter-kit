<?php

declare(strict_types=1);

namespace App\Contracts\Temporal;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'PeriodicScans.')]
interface ListDueWorkActivityInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    #[ActivityMethod(name: 'listDueReminders')]
    public function listDueReminders(): array;

    /**
     * @return list<array<string, mixed>>
     */
    #[ActivityMethod(name: 'listDateRuleCases')]
    public function listDateRuleCases(): array;

    /**
     * @return list<array<string, mixed>>
     */
    #[ActivityMethod(name: 'listDueNotificationDigests')]
    public function listDueNotificationDigests(): array;
}
