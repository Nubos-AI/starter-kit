<?php

declare(strict_types=1);

namespace App\Activities\Notifications;

use App\Actions\Notifications\ClaimNotificationDigestCycleAction;
use App\Actions\Notifications\ReleaseNotificationDigestCycleAction;
use App\Actions\Notifications\SendNotificationDigestAction;
use App\Contracts\Notifications\NotificationDigestActivityInterface;
use App\DTOs\Notifications\NotificationDigestCase;
use App\DTOs\Notifications\NotificationDigestClaim;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;
use Carbon\CarbonImmutable;

class NotificationDigestActivity implements NotificationDigestActivityInterface
{
    public function __construct(
        private readonly ClaimNotificationDigestCycleAction $claimCycle,
        private readonly SendNotificationDigestAction $sendDigest,
        private readonly ReleaseNotificationDigestCycleAction $releaseCycle,
        private readonly ProcessStepRunner $steps,
        private readonly TenantBinder $tenantBinder,
    ) {}

    public function claimDigestCycle(NotificationDigestCase $case): NotificationDigestClaim
    {
        return $this->steps->run('notifications.digest.claim', $this->contextOf($case), fn (): NotificationDigestClaim => $this->tenantBinder->run(
            $case->tenantId,
            fn (): NotificationDigestClaim => $this->claimCycle->execute($case->userId, CarbonImmutable::createFromTimestampUTC($case->cycleStartTimestamp)),
        ));
    }

    public function sendDigest(NotificationDigestCase $case, NotificationDigestClaim $claim): void
    {
        $this->steps->run('notifications.digest.send', $this->contextOf($case), fn () => $this->tenantBinder->run(
            $case->tenantId,
            fn () => $this->sendDigest->execute($case->userId, $claim),
        ));
    }

    public function releaseDigestCycle(NotificationDigestCase $case, NotificationDigestClaim $claim): void
    {
        $this->steps->run('notifications.digest.release', $this->contextOf($case), fn () => $this->tenantBinder->run(
            $case->tenantId,
            fn () => $this->releaseCycle->execute($claim),
        ));
    }

    /**
     * @return array{tenant_id: string, user_id: string, cycle_start: int}
     */
    private function contextOf(NotificationDigestCase $case): array
    {
        return ['tenant_id' => $case->tenantId, 'user_id' => $case->userId, 'cycle_start' => $case->cycleStartTimestamp];
    }
}
