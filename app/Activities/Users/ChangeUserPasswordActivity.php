<?php

declare(strict_types=1);

namespace App\Activities\Users;

use App\Actions\Users\NotifyUserPasswordChangedAction;
use App\Actions\Users\SetUserPasswordAction;
use App\Contracts\Users\ChangeUserPasswordActivityInterface;
use App\DTOs\Users\ChangeUserPasswordData;
use App\Support\Temporal\ProcessStepRunner;
use App\Support\Tenancy\TenantBinder;
use App\Traits\Users\LoadsTenantUser;
use Illuminate\Contracts\Encryption\Encrypter;

class ChangeUserPasswordActivity implements ChangeUserPasswordActivityInterface
{
    use LoadsTenantUser;

    public function __construct(
        private readonly SetUserPasswordAction $setUserPassword,
        private readonly NotifyUserPasswordChangedAction $notifyPasswordChanged,
        private readonly ProcessStepRunner $steps,
        private readonly TenantBinder $tenantBinder,
        private readonly Encrypter $encrypter,
    ) {}

    public function setUserPassword(ChangeUserPasswordData $input): void
    {
        $this->steps->run(
            'users.set-password',
            ['tenant_id' => $input->tenantId, 'user_id' => $input->userId],
            fn () => $this->tenantBinder->run($input->tenantId, fn () => $this->setUserPassword->execute(
                $this->tenantUser($input->tenantId, $input->userId),
                $input->unsealedInput($this->encrypter),
            )),
        );
    }

    public function notifyPasswordChanged(string $tenantId, string $userId, string $deliveryKey): void
    {
        $this->steps->run(
            'users.notify-password-changed',
            ['tenant_id' => $tenantId, 'user_id' => $userId],
            fn () => $this->tenantBinder->run($tenantId, fn () => $this->notifyPasswordChanged->execute(
                $this->tenantUser($tenantId, $userId),
                $deliveryKey,
            )),
        );
    }
}
