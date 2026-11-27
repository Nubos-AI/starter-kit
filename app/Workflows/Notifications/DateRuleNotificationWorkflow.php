<?php

declare(strict_types=1);

namespace App\Workflows\Notifications;

use App\Contracts\Notifications\DateRuleNotificationActivityInterface;
use App\Contracts\Notifications\DateRuleNotificationWorkflowInterface;
use App\DTOs\Notifications\DateRuleCase;
use App\Support\Temporal\BoundedActivityOptions;
use Carbon\CarbonInterval;
use Generator;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;

class DateRuleNotificationWorkflow implements DateRuleNotificationWorkflowInterface
{
    public function run(DateRuleCase $case): Generator
    {
        $deliveredCount = 0;
        $firstFailure = null;

        foreach ($case->recipientIds as $recipientId) {
            try {
                if (yield $this->steps()->deliverRuleNotification($case, $recipientId)) {
                    $deliveredCount++;
                }
            } catch (ActivityFailure $failure) {
                $firstFailure ??= $failure;

                Workflow::getLogger()->error('Notification rule could not reach a recipient; the next scan retries this recipient.', [
                    ...$this->logContext($case),
                    'user_id' => $recipientId,
                    'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
                ]);
            }
        }

        if ($case->hasFollowUpReminder && ($deliveredCount > 0 || $case->recipientIds === [])) {
            try {
                yield $this->steps()->createRuleFollowUpReminder($case);
            } catch (ActivityFailure $failure) {
                Workflow::getLogger()->error('Notification rule follow-up reminder failed.', [
                    ...$this->logContext($case),
                    'error' => ($failure->getPrevious() ?? $failure)->getMessage(),
                ]);

                throw $failure;
            }
        }

        if ($firstFailure instanceof ActivityFailure) {
            throw $firstFailure;
        }

        Workflow::getLogger()->info('Date-based notification rule delivered.', [...$this->logContext($case), 'delivered' => $deliveredCount]);
    }

    /**
     * @return ActivityProxy<DateRuleNotificationActivityInterface>
     */
    private function steps(): ActivityProxy
    {
        return BoundedActivityOptions::make(CarbonInterval::seconds((int) config('temporal.processes.step_start_to_close_seconds')))
            ->build(DateRuleNotificationActivityInterface::class);
    }

    /**
     * @return array{workflow_id: string, run_id: string|null, tenant_id: string, rule_id: string, record_id: string, stage: string}
     */
    private function logContext(DateRuleCase $case): array
    {
        $execution = Workflow::getInfo()->execution;

        return [
            'workflow_id' => $execution->getID(),
            'run_id' => $execution->getRunID(),
            'tenant_id' => $case->tenantId,
            'rule_id' => $case->ruleId,
            'record_id' => $case->recordId,
            'stage' => $case->stage,
        ];
    }
}
