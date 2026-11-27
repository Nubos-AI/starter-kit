<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Contracts\Outbox\OutboxWriter;
use App\DTOs\Outbox\OutboxSubject;
use App\Enums\Outbox\OutboxTopic;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IssueInvitationAction
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    public function execute(User $user, User $invitedBy): void
    {
        $this->outbox->record(
            OutboxTopic::UserInvitationRequested->value,
            (string) $user->tenant_id,
            OutboxSubject::of($user),
            [
                'inviter_name' => $invitedBy->name === '' ? $invitedBy->email : $invitedBy->name,
                'invitation_key' => (string) Str::ulid(),
            ],
        );

        Log::info('A user invitation was requested and handed to the invitation process.', ['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey()]);
    }
}
