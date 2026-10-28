<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Users\UserStatus;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ResendInvitationAction
{
    private string $notPendingReason = 'i18n.backend.actions.users.resend_invitation_action.only_an_open_invitation_can_be_resent';

    public function __construct(private readonly IssueInvitationAction $issueInvitation) {}

    public function execute(User $target, User $actingUser): void
    {
        if ($target->status !== UserStatus::Invited) {
            throw ValidationException::withMessages(['emails' => __($this->notPendingReason)]);
        }

        $this->issueInvitation->execute($target, $actingUser);
    }
}
