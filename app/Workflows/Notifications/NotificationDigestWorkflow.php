<?php

declare(strict_types=1);

namespace App\Workflows\Notifications;

use App\Contracts\Notifications\NotificationDigestActivityInterface;
use App\Contracts\Notifications\NotificationDigestWorkflowInterface;
use App\DTOs\Notifications\NotificationDigestCase;
use App\DTOs\Notifications\NotificationDigestClaim;
use App\Support\Temporal\BoundedActivityOptions;
use Carbon\CarbonInterval;
use Generator;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Throwable;

class NotificationDigestWorkflow implements NotificationDigestWorkflowInterface
{
    public function run(NotificationDigestCase $case): Generator
    {
        /** @var NotificationDigestClaim $claim */
        $claim = yield $this->steps()->claimDigestCycle($case);

        if (!$claim->isClaimed) {
            Workflow::getLogger()->info('Notification digest cycle was already sent.', $this->logContext($case));

            return;
        }

        try {
            yield $this->steps()->sendDigest($case, $claim);
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Notification digest failed; the cycle is released so the next scan retries it.', [
                ...$this->logContext($case),
                'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
            ]);

            yield $this->steps()->releaseDigestCycle($case, $claim);

            throw $failure;
        }

        Workflow::getLogger()->info('Notification digest handed to delivery.', $this->logContext($case));
    }

    /**
     * @return ActivityProxy<NotificationDigestActivityInterface>
     */
    private function steps(): ActivityProxy
    {
        return BoundedActivityOptions::make(CarbonInterval::seconds((int) config('temporal.processes.step_start_to_close_seconds')))
            ->build(NotificationDigestActivityInterface::class);
    }

    /**
     * @return array{workflow_id: string, run_id: string|null, tenant_id: string, user_id: string, cycle_start: int}
     */
    private function logContext(NotificationDigestCase $case): array
    {
        $execution = Workflow::getInfo()->execution;

        return [
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            'tenant_id' => $case->tenantId,
            'user_id' => $case->userId,
            'cycle_start' => $case->cycleStartTimestamp,
        ];
    }
}
