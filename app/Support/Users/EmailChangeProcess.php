<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Contracts\Temporal\ProcessStarterInterface;
use App\Contracts\Users\ConfirmEmailChangeWorkflowInterface;
use App\Contracts\Users\RequestEmailChangeWorkflowInterface;
use App\DTOs\Temporal\ProcessStartData;
use App\DTOs\Users\SealedUserValueData;
use App\Exceptions\Temporal\ProcessStepFailedException;
use App\Exceptions\Temporal\ProcessStepRejectedException;
use App\Models\User;
use App\Support\Temporal\ProcessFingerprints;
use App\Support\Temporal\ProcessId;
use Illuminate\Contracts\Encryption\Encrypter;

class EmailChangeProcess
{
    public function __construct(
        private readonly ProcessStarterInterface $processes,
        private readonly Encrypter $encrypter,
        private readonly ProcessFingerprints $fingerprints,
    ) {}

    /**
     * @throws ProcessStepRejectedException
     * @throws ProcessStepFailedException
     */
    public function request(User $user, string $email): void
    {
        $this->startWithFirstStep(RequestEmailChangeWorkflowInterface::class, 'user-email-change-request', 'requestEmailChange', $user, $email);
    }

    /**
     * @throws ProcessStepRejectedException
     * @throws ProcessStepFailedException
     */
    public function confirm(User $user, string $token): void
    {
        $this->startWithFirstStep(ConfirmEmailChangeWorkflowInterface::class, 'user-email-change-confirmation', 'confirmEmailChange', $user, $token);
    }

    /**
     * @param  class-string  $workflow
     * @param  non-empty-string  $update
     *
     * @throws ProcessStepRejectedException
     * @throws ProcessStepFailedException
     */
    private function startWithFirstStep(string $workflow, string $process, string $update, User $user, string $value): void
    {
        $tenantId = (string) $user->tenant_id;
        $userId = (string) $user->getKey();
        $input = SealedUserValueData::sealed($this->encrypter, $tenantId, $userId, $value);

        $this->processes->startWithUpdate(
            new ProcessStartData(
                workflow: $workflow,
                workflowId: ProcessId::forTenant($process, $tenantId, $userId, $this->fingerprints->of([$input])),
                arguments: [$input],
            ),
            $update,
            [$input],
        );
    }
}
