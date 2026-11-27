<?php

declare(strict_types=1);

namespace App\Contracts\Users;

use App\DTOs\Users\SealedUserTokenData;
use App\DTOs\Users\SealedUserValueData;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'users.')]
interface EmailChangeActivityInterface
{
    #[ActivityMethod(name: 'requestEmailChange')]
    public function requestEmailChange(SealedUserValueData $input): SealedUserTokenData;

    #[ActivityMethod(name: 'sendEmailChangeConfirmation')]
    public function sendEmailChangeConfirmation(SealedUserTokenData $token, string $deliveryKey): void;

    #[ActivityMethod(name: 'confirmEmailChange')]
    public function confirmEmailChange(SealedUserValueData $input): SealedUserValueData;

    #[ActivityMethod(name: 'notifyPreviousEmailAddress')]
    public function notifyPreviousEmailAddress(SealedUserValueData $previousEmail, string $deliveryKey): void;
}
