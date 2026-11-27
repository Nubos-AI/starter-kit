<?php

declare(strict_types=1);

namespace App\Workflows\Reminders;

use App\Contracts\Reminders\DueReminderActivityInterface;
use App\Contracts\Reminders\DueReminderWorkflowInterface;
use App\DTOs\Reminders\DueReminderCase;
use App\DTOs\Reminders\DueReminderClaim;
use App\Support\Temporal\BoundedActivityOptions;
use Carbon\CarbonInterval;
use Generator;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;
use Throwable;

class DueReminderWorkflow implements DueReminderWorkflowInterface
{
    public function run(DueReminderCase $case): Generator
    {
        /** @var DueReminderClaim $claim */
        $claim = yield $this->steps()->claimDueReminder($case);

        if (!$claim->isClaimed || $claim->claimedAt === null) {
            Workflow::getLogger()->info('Due reminder was no longer due or already claimed.', $this->logContext($case));

            return;
        }

        try {
            foreach ($claim->pendingChannels as $channel) {
                yield $this->steps()->deliverDueReminderChannel($case, $channel);
            }
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Due reminder notification failed; the claim is released so the next scan retries it.', [
                ...$this->logContext($case),
                'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
            ]);

            yield $this->steps()->releaseDueReminderClaim($case, $claim->claimedAt);

            throw $failure;
        }

        try {
            yield $this->steps()->completeDueReminder($case, $claim->claimedAt);
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Due reminder timeline entry failed after the notification was delivered.', [
                ...$this->logContext($case),
                'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
            ]);

            throw $failure;
        }

        Workflow::getLogger()->info('Due reminder delivered.', [...$this->logContext($case), 'channels' => count($claim->pendingChannels)]);
    }

    /**
     * @return ActivityProxy<DueReminderActivityInterface>
     */
    private function steps(): ActivityProxy
    {
        return BoundedActivityOptions::make(CarbonInterval::seconds((int) config('temporal.processes.step_start_to_close_seconds')))
            ->build(DueReminderActivityInterface::class);
    }

    /**
     * @return array{workflow_id: string, run_id: string|null, tenant_id: string, reminder_id: string}
     */
    private function logContext(DueReminderCase $case): array
    {
        $execution = Workflow::getInfo()->execution;

        return [
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            'tenant_id' => $case->tenantId,
            'reminder_id' => $case->reminderId,
        ];
    }
}
