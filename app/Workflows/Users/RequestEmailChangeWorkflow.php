<?php

declare(strict_types=1);

namespace App\Workflows\Users;

use App\Contracts\Users\EmailChangeActivityInterface;
use App\Contracts\Users\RequestEmailChangeWorkflowInterface;
use App\DTOs\Users\SealedUserTokenData;
use App\DTOs\Users\SealedUserValueData;
use App\Traits\Temporal\RunsProcessSteps;
use Generator;
use Temporal\Workflow;
use Throwable;

class RequestEmailChangeWorkflow implements RequestEmailChangeWorkflowInterface
{
    use RunsProcessSteps;

    public function run(SealedUserValueData $input): Generator
    {
        $context = $this->processLogContext(['tenant_id' => $input->tenantId, 'user_id' => $input->userId]);

        $isRequested = yield from $this->awaitFirstStep();
        $token = $this->firstStepResult;

        if (!$isRequested || !$token instanceof SealedUserTokenData) {
            Workflow::getLogger()->info('Email change request ended without an open request.', $context);

            return;
        }

        try {
            yield $this->processSteps(EmailChangeActivityInterface::class)->sendEmailChangeConfirmation($token, $this->processRunId());
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Email change request could not ask the new address for confirmation.', [...$context, 'error' => $this->failureMessage($failure)]);

            throw $failure;
        }

        Workflow::getLogger()->info('Email change request finished.', $context);
    }

    public function requestEmailChange(SealedUserValueData $input): Generator
    {
        yield from $this->answerFirstStep(
            fn () => $this->processSteps(EmailChangeActivityInterface::class)->requestEmailChange($input),
        );

        return true;
    }
}
