<?php

declare(strict_types=1);

namespace App\Workflows\Users;

use App\Contracts\Users\PurgeUnverifiedRegistrationsActivityInterface;
use App\Contracts\Users\PurgeUnverifiedRegistrationsWorkflowInterface;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Common\RetryOptions;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow;

class PurgeUnverifiedRegistrationsWorkflow implements PurgeUnverifiedRegistrationsWorkflowInterface
{
    public function run(): Generator
    {
        $activity = Temporal::newActivity()
            ->withStartToCloseTimeout(
                CarbonInterval::seconds((int) config('users.registration.purge_activity_timeout')),
            )
            ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(1))
            ->build(PurgeUnverifiedRegistrationsActivityInterface::class);

        $logger = Workflow::getLogger();
        $execution = Workflow::getInfo()->execution;
        $run = ['workflow_id' => $execution->getID(), 'run_id' => $execution->getRunID()];

        try {
            $listed = yield $activity->abandonedRegistrationIds();
        } catch (ActivityFailure $failure) {
            $logger->error('Unverified registration purge could not list the registrations.', $run);

            throw $failure;
        }

        $userIds = array_values(array_filter(is_array($listed) ? $listed : [], is_string(...)));
        $released = 0;
        $kept = 0;
        $failed = 0;
        $firstFailure = null;

        foreach ($userIds as $userId) {
            try {
                $isReleased = yield $activity->releaseRegistration($userId);
            } catch (ActivityFailure $failure) {
                $failed++;
                $firstFailure ??= $failure;

                $logger->error('Releasing an unverified registration failed; the remaining registrations still run.', ['user_id' => $userId, ...$run]);

                continue;
            }

            if ($isReleased === true) {
                $released++;

                continue;
            }

            $kept++;
        }

        $outcome = ['registrations' => count($userIds), 'released' => $released, 'kept' => $kept, 'failed' => $failed, ...$run];

        if ($firstFailure instanceof ActivityFailure) {
            $logger->error('Unverified registration purge finished with failures.', $outcome);

            throw $firstFailure;
        }

        $logger->info('Unverified registration purge finished.', $outcome);
    }
}
