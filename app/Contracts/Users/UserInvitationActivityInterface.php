<?php

declare(strict_types=1);

namespace App\Contracts\Users;

use App\DTOs\Users\SealedUserTokenData;
use App\DTOs\Users\UserInvitationData;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'users.')]
interface UserInvitationActivityInterface
{
    #[ActivityMethod(name: 'issueInvitationToken')]
    public function issueInvitationToken(UserInvitationData $input): SealedUserTokenData;

    #[ActivityMethod(name: 'sendInvitation')]
    public function sendInvitation(UserInvitationData $input, SealedUserTokenData $token, string $deliveryKey): void;
}
