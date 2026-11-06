<?php

declare(strict_types=1);

namespace App\Workflows\Reports;

use App\Contracts\Reports\EnsureReportIndexesActivityInterface;
use App\Contracts\Reports\EnsureReportIndexesWorkflowInterface;
use Carbon\CarbonInterval;
use Generator;
use InvalidArgumentException;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Common\RetryOptions;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow;

class EnsureReportIndexesWorkflow implements EnsureReportIndexesWorkflowInterface
{
    public function run(string $objectTypeId, string $fieldKey): Generator
    {
        $activity = Temporal::newActivity()
            ->withStartToCloseTimeout(
                CarbonInterval::seconds((int) config('reports.index_maintenance.start_to_close')),
            )
            ->withRetryOptions($this->retryOptions())
            ->build(EnsureReportIndexesActivityInterface::class);

        $logger = Workflow::getLogger();

        $context = [
            'object_type_id' => $objectTypeId,
            'field_key' => $fieldKey,
        ];

        try {
            $created = yield $activity->ensureReportIndex($objectTypeId, $fieldKey);
        } catch (ActivityFailure $failure) {
            $logger->error('Report expression index maintenance failed.', $context);

            throw $failure;
        }

        $logger->info('Report expression index maintenance finished.', [...$context, 'created' => $created]);
    }

    private function retryOptions(): RetryOptions
    {
        return RetryOptions::new()
            ->withMaximumAttempts(max(1, (int) config('reports.index_maintenance.retry.max_attempts')))
            ->withInitialInterval(
                CarbonInterval::seconds((int) config('reports.index_maintenance.retry.initial_interval')),
            )
            ->withBackoffCoefficient((float) config('reports.index_maintenance.retry.backoff_coefficient'))
            ->withMaximumInterval(
                CarbonInterval::seconds((int) config('reports.index_maintenance.retry.maximum_interval')),
            )
            ->withNonRetryableExceptions([InvalidArgumentException::class]);
    }
}
