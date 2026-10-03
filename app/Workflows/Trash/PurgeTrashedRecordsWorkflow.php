<?php

declare(strict_types=1);

namespace App\Workflows\Trash;

use App\Contracts\Trash\PurgeTrashedRecordsActivityInterface;
use App\Contracts\Trash\PurgeTrashedRecordsWorkflowInterface;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Common\RetryOptions;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow;

class PurgeTrashedRecordsWorkflow implements PurgeTrashedRecordsWorkflowInterface
{
    public function run(): Generator
    {
        $activity = Temporal::newActivity()
            ->withStartToCloseTimeout(
                CarbonInterval::seconds((int) config('engine.trash.purge_activity_timeout')),
            )
            ->withRetryOptions(RetryOptions::new()->withMaximumAttempts(1))
            ->build(PurgeTrashedRecordsActivityInterface::class);

        $logger = Workflow::getLogger();

        $recordIds = [];
        $failures = 0;

        try {
            $expired = yield $activity->expiredRecordIds();

            /** @var list<string> $recordIds */
            $recordIds = is_array($expired) ? array_values($expired) : [];

            foreach ($recordIds as $recordId) {
                $purged = yield $activity->purgeRecord($recordId);

                if ($purged !== true) {
                    $failures++;
                }
            }
        } catch (ActivityFailure $failure) {
            $logger->error('Trash purge failed.', [
                'records' => count($recordIds),
                'failures' => $failures,
            ]);

            throw $failure;
        }

        $logger->info('Trash purge finished.', [
            'records' => count($recordIds),
            'failures' => $failures,
        ]);
    }
}
