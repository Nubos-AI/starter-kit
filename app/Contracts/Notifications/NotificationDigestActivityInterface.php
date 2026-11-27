<?php

declare(strict_types=1);

namespace App\Contracts\Notifications;

use App\DTOs\Notifications\NotificationDigestCase;
use App\DTOs\Notifications\NotificationDigestClaim;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'notifications.digest.')]
interface NotificationDigestActivityInterface
{
    #[ActivityMethod(name: 'claimDigestCycle')]
    public function claimDigestCycle(NotificationDigestCase $case): NotificationDigestClaim;

    #[ActivityMethod(name: 'sendDigest')]
    public function sendDigest(NotificationDigestCase $case, NotificationDigestClaim $claim): void;

    #[ActivityMethod(name: 'releaseDigestCycle')]
    public function releaseDigestCycle(NotificationDigestCase $case, NotificationDigestClaim $claim): void;
}
