<?php

declare(strict_types=1);

namespace App\Workflows\Users;

use App\Contracts\Users\UserInvitationActivityInterface;
use App\Contracts\Users\UserInvitationWorkflowInterface;
use App\DTOs\Users\SealedUserTokenData;
use App\DTOs\Users\UserInvitationData;
use App\Traits\Temporal\RunsProcessSteps;
use Generator;
use Temporal\Workflow;
use Throwable;

class UserInvitationWorkflow implements UserInvitationWorkflowInterface
{
    use RunsProcessSteps;

    public function run(UserInvitationData $input): Generator
    {
        $context = $this->processLogContext(['tenant_id' => $input->tenantId, 'user_id' => $input->userId]);
        $steps = $this->processSteps(UserInvitationActivityInterface::class);

        try {
            $token = yield $steps->issueInvitationToken($input);

            if (!$token instanceof SealedUserTokenData || !$token->isIssued()) {
                Workflow::getLogger()->info('User invitation ended without a new invitation.', $context);

                return;
            }

            yield $steps->sendInvitation($input, $token, $this->processRunId());
        } catch (Throwable $failure) {
            Workflow::getLogger()->error('User invitation failed.', [...$context, 'error' => $this->failureMessage($failure)]);

            throw $failure;
        }

        Workflow::getLogger()->info('User invitation finished.', $context);
    }
}
