<?php

declare(strict_types=1);

namespace App\Workflows\Temporal;

use App\Contracts\Notifications\DateRuleNotificationWorkflowInterface;
use App\Contracts\Notifications\NotificationDigestWorkflowInterface;
use App\Contracts\Reminders\DueReminderWorkflowInterface;
use App\Contracts\Temporal\ListDueWorkActivityInterface;
use App\Contracts\Temporal\PeriodicScansWorkflowInterface;
use App\Contracts\Temporal\RunConsoleCommandActivityInterface;
use App\DTOs\Notifications\DateRuleCase;
use App\DTOs\Notifications\NotificationDigestCase;
use App\DTOs\Reminders\DueReminderCase;
use App\Support\Temporal\ChildProcess;
use App\Support\Temporal\ProcessId;
use App\Support\Temporal\RecordRetryOptions;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow;
use Throwable;

class PeriodicScansWorkflow implements PeriodicScansWorkflowInterface
{
    private int $startedCount = 0;

    private int $skippedCount = 0;

    public function scan(): Generator
    {
        $discovery = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::seconds((int) config('record-processing.scans.list_start_to_close')))
            ->withRetryOptions(RecordRetryOptions::make())
            ->build(ListDueWorkActivityInterface::class);

        $failed = [];
        $firstFailure = null;

        try {
            yield from $this->startEach(yield $discovery->listDueReminders(), fn (array $payload): array => $this->dueReminderProcess(DueReminderCase::fromTemporalPayload($payload)));
        } catch (ActivityFailure $failure) {
            $failed[] = 'due-reminders';
            $firstFailure = $failure;
        }

        try {
            yield from $this->startEach(yield $discovery->listDateRuleCases(), fn (array $payload): array => $this->dateRuleProcess(DateRuleCase::fromTemporalPayload($payload)));
        } catch (ActivityFailure $failure) {
            $failed[] = 'date-rules';
            $firstFailure ??= $failure;
        }

        try {
            yield from $this->startEach(yield $discovery->listDueNotificationDigests(), fn (array $payload): array => $this->digestProcess(NotificationDigestCase::fromTemporalPayload($payload)));
        } catch (ActivityFailure $failure) {
            $failed[] = 'notification-digests';
            $firstFailure ??= $failure;
        }

        $runner = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::seconds(300))
            ->withRetryOptions(RecordRetryOptions::make())
            ->build(RunConsoleCommandActivityInterface::class);

        $listed = yield $runner->periodicCommands();

        foreach (array_values(array_filter(is_array($listed) ? $listed : [], is_string(...))) as $command) {
            try {
                yield $runner->runConsoleCommand($command);
            } catch (ActivityFailure $failure) {
                $failed[] = $command;
                $firstFailure ??= $failure;
            }
        }

        $context = [...$this->logContext(), 'started' => $this->startedCount, 'skipped' => $this->skippedCount, 'failed' => $failed];

        if ($firstFailure instanceof ActivityFailure) {
            Workflow::getLogger()->error('Periodic scans finished with failures; the remaining scans still ran.', $context);

            throw $firstFailure;
        }

        Workflow::getLogger()->info('Periodic scans finished.', $context);
    }

    /**
     * @param  callable(array<string, mixed>): array{0: class-string, 1: non-empty-string, 2: object}  $process
     */
    private function startEach(mixed $payloads, callable $process): Generator
    {
        foreach (is_array($payloads) ? $payloads : [] as $payload) {
            if (!is_array($payload)) {
                continue;
            }

            /** @var array<string, mixed> $payload */
            [$workflow, $workflowId, $case] = $process($payload);

            try {
                yield ChildProcess::detached($workflow, $workflowId, [$case]);
                $this->startedCount++;
            } catch (Throwable $failure) {
                $this->skippedCount++;

                Workflow::getLogger()->info('Due case was not started because its workflow is still running.', [
                    ...$this->logContext(),
                    'case_workflow_id' => $workflowId,
                    'reason' => $failure::class,
                ]);
            }
        }
    }

    /**
     * @return array{0: class-string, 1: non-empty-string, 2: DueReminderCase}
     */
    private function dueReminderProcess(DueReminderCase $case): array
    {
        return [DueReminderWorkflowInterface::class, ProcessId::forTenant('reminder-due', $case->tenantId, $case->reminderId), $case];
    }

    /**
     * @return array{0: class-string, 1: non-empty-string, 2: DateRuleCase}
     */
    private function dateRuleProcess(DateRuleCase $case): array
    {
        return [DateRuleNotificationWorkflowInterface::class, ProcessId::forTenant('date-rule', $case->tenantId, $case->ruleId, $case->recordId, $case->stageKey()), $case];
    }

    /**
     * @return array{0: class-string, 1: non-empty-string, 2: NotificationDigestCase}
     */
    private function digestProcess(NotificationDigestCase $case): array
    {
        return [NotificationDigestWorkflowInterface::class, ProcessId::forTenant('notification-digest', $case->tenantId, $case->userId, (string) $case->cycleStartTimestamp), $case];
    }

    /**
     * @return array{workflow_id: string, run_id: string|null}
     */
    private function logContext(): array
    {
        $execution = Workflow::getInfo()->execution;

        return ['workflow_id' => $execution->getID(), 'run_id' => $execution->getRunID()];
    }
}
