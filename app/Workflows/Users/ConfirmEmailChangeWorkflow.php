<?php

declare(strict_types=1);

namespace App\Workflows\Users;

use App\Contracts\Users\ConfirmEmailChangeWorkflowInterface;
use App\Contracts\Users\EmailChangeActivityInterface;
use App\DTOs\Users\SealedUserValueData;
use App\Traits\Temporal\RunsProcessSteps;
use Generator;
use Temporal\Workflow;
use Throwable;

class ConfirmEmailChangeWorkflow implements ConfirmEmailChangeWorkflowInterface
{
    use RunsProcessSteps;

    public function run(SealedUserValueData $input): Generator
    {
        $context = $this->processLogContext(['tenant_id' => $input->tenantId, 'user_id' => $input->userId]);

        $isConfirmed = yield from $this->awaitFirstStep();
        $previousEmail = $this->firstStepResult;

        if (!$isConfirmed) {
            Workflow::getLogger()->info('Email change confirmation ended without a confirmed change.', $context);

            return;
        }

        if (!$previousEmail instanceof SealedUserValueData || !$previousEmail->hasValue()) {
            Workflow::getLogger()->info('Email change confirmed without telling the previous address.', $context);

            return;
        }

        try {
            yield $this->processSteps(EmailChangeActivityInterface::class)->notifyPreviousEmailAddress($previousEmail, $this->processRunId());
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('Email change confirmation could not tell the previous address.', [...$context, 'error' => $this->failureMessage($failure)]);

            throw $failure;
        }

        Workflow::getLogger()->info('Email change confirmation finished.', $context);
    }

    public function confirmEmailChange(SealedUserValueData $input): Generator
    {
        yield from $this->answerFirstStep(
            fn () => $this->processSteps(EmailChangeActivityInterface::class)->confirmEmailChange($input),
        );

        return true;
    }
}
