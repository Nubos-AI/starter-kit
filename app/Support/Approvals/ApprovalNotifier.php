<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Enums\Approvals\ApprovalEscalationType;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcessStage;
use App\Notifications\Abstracts\EngineNotification;
use App\Notifications\Approvals\ApprovalDecidedNotification;
use App\Notifications\Approvals\ApprovalEscalatedNotification;
use App\Notifications\Approvals\ApprovalRequestedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApprovalNotifier
{
    public function __construct(
        private readonly ApprovalStageEligibility $eligibility,
        private readonly ApprovalSubjectSource $subjects,
    ) {}

    public function notifyStageStarted(ApprovalProcessStage $stage): void
    {
        DB::afterCommit(function () use ($stage): void {
            $this->send(
                $stage->tenant_id,
                fn (): array => $this->eligibility->notificationRecipientIds($stage),
                fn (): EngineNotification => new ApprovalRequestedNotification($stage),
                [
                    'approval_process_id' => $stage->approval_process_id,
                    'approval_process_stage_id' => (string) $stage->getKey(),
                ],
            );
        });
    }

    public function notifyDecision(ApprovalEvent $event): void
    {
        DB::afterCommit(function () use ($event): void {
            $this->send(
                $event->tenant_id,
                fn (): array => $this->decisionRecipientIds($event),
                fn (): EngineNotification => new ApprovalDecidedNotification($event),
                [
                    'approval_process_id' => $event->approval_process_id,
                    'approval_event_id' => (string) $event->getKey(),
                ],
            );
        });
    }

    public function notifyEscalation(ApprovalProcessStage $stage, ApprovalEscalationType $type): void
    {
        DB::afterCommit(function () use ($stage, $type): void {
            $this->send(
                $stage->tenant_id,
                fn (): array => $this->eligibility->eligibleUserIds($stage),
                fn (): EngineNotification => new ApprovalEscalatedNotification($stage, $type),
                [
                    'approval_process_id' => $stage->approval_process_id,
                    'approval_process_stage_id' => (string) $stage->getKey(),
                    'escalation_type' => $type->value,
                ],
            );
        });
    }

    /**
     * @param  callable(): list<string>  $resolveRecipientIds
     * @param  callable(): EngineNotification  $buildNotification
     * @param  array<string, string>  $context
     */
    private function send(
        string $tenantId,
        callable $resolveRecipientIds,
        callable $buildNotification,
        array $context,
    ): void {
        try {
            $recipientIds = $resolveRecipientIds();

            if ($recipientIds === []) {
                return;
            }

            $notification = $buildNotification();
            $recipients = $this->subjects->recipients($tenantId, $recipientIds);
        } catch (Throwable $exception) {
            Log::error('Failed to prepare an approval notification.', [
                ...$context,
                'tenant_id' => $tenantId,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify($notification);
            } catch (Throwable $exception) {
                Log::error('Failed to deliver an approval notification to a recipient.', [
                    ...$context,
                    'tenant_id' => $tenantId,
                    'recipient_id' => (string) $recipient->getKey(),
                    'notification_type' => $notification->type(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function decisionRecipientIds(ApprovalEvent $event): array
    {
        $process = $this->subjects->process($event);
        $record = $this->subjects->recordIfStillThere($process);

        return array_values(array_unique(array_filter(
            [$process->triggered_by_id, $record?->owner_id],
            static fn (?string $userId): bool => $userId !== null && $userId !== '',
        )));
    }
}
