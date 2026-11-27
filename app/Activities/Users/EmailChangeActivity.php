<?php

declare(strict_types=1);

namespace App\Activities\Users;

use App\Actions\Users\ConfirmEmailChangeAction;
use App\Actions\Users\NotifyPreviousEmailAddressAction;
use App\Actions\Users\RequestEmailChangeAction;
use App\Actions\Users\SendEmailChangeConfirmationAction;
use App\Contracts\Users\EmailChangeActivityInterface;
use App\DTOs\Users\SealedUserTokenData;
use App\DTOs\Users\SealedUserValueData;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;
use App\Traits\Users\LoadsTenantUser;
use Illuminate\Contracts\Encryption\Encrypter;

class EmailChangeActivity implements EmailChangeActivityInterface
{
    use LoadsTenantUser;

    public function __construct(
        private readonly RequestEmailChangeAction $requestEmailChange,
        private readonly SendEmailChangeConfirmationAction $sendConfirmation,
        private readonly ConfirmEmailChangeAction $confirmEmailChange,
        private readonly NotifyPreviousEmailAddressAction $notifyPreviousEmail,
        private readonly ProcessStepRunner $steps,
        private readonly TenantBinder $tenantBinder,
        private readonly Encrypter $encrypter,
    ) {}

    public function requestEmailChange(SealedUserValueData $input): SealedUserTokenData
    {
        return $this->steps->run(
            'users.request-email-change',
            ['tenant_id' => $input->tenantId, 'user_id' => $input->userId],
            fn (): SealedUserTokenData => $this->tenantBinder->run($input->tenantId, fn (): SealedUserTokenData => SealedUserTokenData::sealed(
                $this->encrypter,
                $input->tenantId,
                $input->userId,
                $this->requestEmailChange->execute(
                    $this->tenantUser($input->tenantId, $input->userId),
                    $input->unsealedValue($this->encrypter),
                ),
            )),
        );
    }

    public function sendEmailChangeConfirmation(SealedUserTokenData $token, string $deliveryKey): void
    {
        $this->steps->run(
            'users.send-email-change-confirmation',
            ['tenant_id' => $token->tenantId, 'user_id' => $token->userId],
            fn () => $this->tenantBinder->run($token->tenantId, fn () => $this->sendConfirmation->execute(
                $this->tenantUser($token->tenantId, $token->userId),
                $token->unsealedToken($this->encrypter),
                $token->expiresAt(),
                $deliveryKey,
            )),
        );
    }

    public function confirmEmailChange(SealedUserValueData $input): SealedUserValueData
    {
        return $this->steps->run(
            'users.confirm-email-change',
            ['tenant_id' => $input->tenantId, 'user_id' => $input->userId],
            fn (): SealedUserValueData => $this->tenantBinder->run($input->tenantId, function () use ($input): SealedUserValueData {
                $previousEmail = $this->confirmEmailChange->execute(
                    $this->tenantUser($input->tenantId, $input->userId),
                    $input->unsealedValue($this->encrypter),
                );

                return $previousEmail === null
                    ? SealedUserValueData::empty($input->tenantId, $input->userId)
                    : SealedUserValueData::sealed($this->encrypter, $input->tenantId, $input->userId, $previousEmail);
            }),
        );
    }

    public function notifyPreviousEmailAddress(SealedUserValueData $previousEmail, string $deliveryKey): void
    {
        $this->steps->run(
            'users.notify-previous-email-address',
            ['tenant_id' => $previousEmail->tenantId, 'user_id' => $previousEmail->userId],
            fn () => $this->tenantBinder->run($previousEmail->tenantId, fn () => $this->notifyPreviousEmail->execute(
                $this->tenantUser($previousEmail->tenantId, $previousEmail->userId),
                $previousEmail->unsealedValue($this->encrypter),
                $deliveryKey,
            )),
        );
    }
}
