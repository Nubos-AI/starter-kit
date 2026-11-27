<?php

declare(strict_types=1);

namespace App\Workflows\Users;

use App\Contracts\Users\ChangeUserPasswordActivityInterface;
use App\Contracts\Users\ChangeUserPasswordWorkflowInterface;
use App\DTOs\Users\ChangeUserPasswordData;
use App\Traits\Temporal\RunsProcessSteps;
use Generator;
use Temporal\Workflow;
use Throwable;

class ChangeUserPasswordWorkflow implements ChangeUserPasswordWorkflowInterface
{
    use RunsProcessSteps;

    public function run(ChangeUserPasswordData $input): Generator
    {
        $context = $this->processLogContext(['tenant_id' => $input->tenantId, 'user_id' => $input->userId]);

        $isPasswordSet = yield from $this->awaitFirstStep();

        if (!$isPasswordSet) {
            Workflow::getLogger()->info('Password change ended without a new password.', $context);

            return;
        }

        try {
            yield $this->processSteps(ChangeUserPasswordActivityInterface::class)->notifyPasswordChanged($input->tenantId, $input->userId, $this->processRunId());
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Password change could not notify the user.', [...$context, 'error' => $this->failureMessage($failure)]);

            throw $failure;
        }

        Workflow::getLogger()->info('Password change finished.', $context);
    }

    public function setPassword(ChangeUserPasswordData $input): Generator
    {
        yield from $this->answerFirstStep(
            fn () => $this->processSteps(ChangeUserPasswordActivityInterface::class)->setUserPassword($input),
        );

        return true;
    }
}
