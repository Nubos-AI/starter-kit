<?php

declare(strict_types=1);

namespace App\Handlers\Users;

use App\Contracts\Outbox\IdempotentOutboxHandler;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\Contracts\Users\UserInvitationWorkflowInterface;
use App\DTOs\Outbox\OutboxMessage;
use App\DTOs\Temporal\ProcessStartData;
use App\DTOs\Users\UserInvitationData;
use App\Enums\Outbox\OutboxTopic;
use App\Support\Temporal\ProcessId;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Temporal\Common\IdReusePolicy;

class UserInvitationOutboxHandler implements IdempotentOutboxHandler
{
    public function __construct(private readonly ProcessStarterInterface $processes) {}

    public function topic(): string
    {
        return OutboxTopic::UserInvitationRequested->value;
    }

    public function handle(OutboxMessage $message): void
    {
        $userId = $message->subject->id;
        $inviterName = $message->payload['inviter_name'] ?? '';
        $invitationKey = $message->payload['invitation_key'] ?? null;

        if (!is_string($invitationKey) || !Str::isUlid($invitationKey)) {
            Log::error('User invitation was given up because its outbox entry carries no valid invitation key.', [
                'topic' => $message->topic,
                'tenant_id' => $message->tenantId,
                'user_id' => $userId,
            ]);

            return;
        }

        $this->processes->start(new ProcessStartData(
            workflow: UserInvitationWorkflowInterface::class,
            workflowId: ProcessId::forTenant('user-invitation', $message->tenantId, $userId, $invitationKey),
            arguments: [new UserInvitationData($message->tenantId, $userId, is_string($inviterName) ? $inviterName : '')],
            reusePolicy: IdReusePolicy::RejectDuplicate,
        ));
    }
}
