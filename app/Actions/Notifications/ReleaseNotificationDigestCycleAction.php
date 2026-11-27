<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\DTOs\Notifications\NotificationDigestClaim;
use App\Models\NotificationDigestState;
use Carbon\CarbonImmutable;

class ReleaseNotificationDigestCycleAction
{
    public function execute(NotificationDigestClaim $claim): void
    {
        if (!$claim->isClaimed || $claim->stateId === null || $claim->sentAt === null) {
            return;
        }

        NotificationDigestState::query()
            ->whereKey($claim->stateId)
            ->where('last_digest_sent_at', CarbonImmutable::parse($claim->sentAt))
            ->toBase()
            ->update(['last_digest_sent_at' => $claim->previousSentAt === null ? null : CarbonImmutable::parse($claim->previousSentAt)]);
    }
}
