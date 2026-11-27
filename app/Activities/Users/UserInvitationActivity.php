<?php

declare(strict_types=1);

namespace App\Activities\Users;

use App\Actions\Users\IssueInvitationTokenAction;
use App\Actions\Users\SendUserInvitationAction;
use App\Contracts\Users\UserInvitationActivityInterface;
use App\DTOs\Users\IssuedUserTokenData;
use App\DTOs\Users\SealedUserTokenData;
use App\DTOs\Users\UserInvitationData;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;
use App\Traits\Users\LoadsTenantUser;
use Illuminate\Contracts\Encryption\Encrypter;

class UserInvitationActivity implements UserInvitationActivityInterface
{
    use LoadsTenantUser;

    public function __construct(
        private readonly IssueInvitationTokenAction $issueInvitationToken,
        private readonly SendUserInvitationAction $sendInvitation,
        private readonly ProcessStepRunner $steps,
        private readonly TenantBinder $tenantBinder,
        private readonly Encrypter $encrypter,
    ) {}

    public function issueInvitationToken(UserInvitationData $input): SealedUserTokenData
    {
        return $this->steps->run(
            'users.issue-invitation-token',
            ['tenant_id' => $input->tenantId, 'user_id' => $input->userId],
            fn (): SealedUserTokenData => $this->tenantBinder->run($input->tenantId, function () use ($input): SealedUserTokenData {
                $issued = $this->issueInvitationToken->execute($this->tenantUser($input->tenantId, $input->userId));

                return $issued instanceof IssuedUserTokenData
                    ? SealedUserTokenData::sealed($this->encrypter, $input->tenantId, $input->userId, $issued)
                    : SealedUserTokenData::notIssued($input->tenantId, $input->userId);
            }),
        );
    }

    public function sendInvitation(UserInvitationData $input, SealedUserTokenData $token, string $deliveryKey): void
    {
        $this->steps->run(
            'users.send-invitation',
            ['tenant_id' => $input->tenantId, 'user_id' => $input->userId],
            fn () => $this->tenantBinder->run($input->tenantId, fn () => $this->sendInvitation->execute(
                $this->tenantUser($input->tenantId, $input->userId),
                $input->inviterName,
                $token->unsealedToken($this->encrypter),
                $token->expiresAt(),
                $deliveryKey,
            )),
        );
    }
}
