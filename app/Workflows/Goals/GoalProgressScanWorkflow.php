<?php

declare(strict_types=1);

namespace App\Workflows\Goals;

use App\Contracts\Goals\ComputeGoalProgressActivityInterface;
use App\Contracts\Goals\GoalProgressScanWorkflowInterface;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Common\RetryOptions;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow;

class GoalProgressScanWorkflow implements GoalProgressScanWorkflowInterface
{
    public function run(): Generator
    {
        $activity = Temporal::newActivity()
            ->withStartToCloseTimeout(
                CarbonInterval::seconds((int) config('reports.goal_progress_interval')),
            )
            ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(1))
            ->build(ComputeGoalProgressActivityInterface::class);

        $logger = Workflow::getLogger();

        $goalIds = [];
        $failures = 0;

        try {
            $due = yield $activity->dueGoalIds();

            /** @var list<string> $goalIds */
            $goalIds = is_array($due) ? array_values($due) : [];

            foreach ($goalIds as $goalId) {
                $recorded = yield $activity->computeGoalProgress($goalId);

                if ($recorded !== true) {
                    $failures++;
                }
            }
        } catch (ActivityFailure $failure) {
            $logger->error('Goal progress scan failed.', [
                'goals' => count($goalIds),
                'failures' => $failures,
            ]);

            throw $failure;
        }

        $logger->info('Goal progress scan finished.', [
            'goals' => count($goalIds),
            'failures' => $failures,
        ]);
    }
}
