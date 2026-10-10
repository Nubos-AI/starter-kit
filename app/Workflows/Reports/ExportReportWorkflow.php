<?php

declare(strict_types=1);

namespace App\Workflows\Reports;

use App\Contracts\Reports\ExportReportActivityInterface;
use App\Contracts\Reports\ExportReportWorkflowInterface;
use App\DTOs\Export\ExportData;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Common\RetryOptions;
use Temporal\Workflow;
use Throwable;

class ExportReportWorkflow implements ExportReportWorkflowInterface
{
    /** @var int<1, max> */
    private int $maximumAttempts = 3;

    public function run(ExportData $input): Generator
    {
        $activity = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(5))
            ->withRetryOptions(RetryOptions::new()->withMaximumAttempts($this->maximumAttempts))
            ->build(ExportReportActivityInterface::class);

        $logger = Workflow::getLogger();

        $context = [
            'tenant_id' => $input->tenantId,
            'acting_user_id' => $input->actingUserId,
            'export_job_id' => $input->exportJobId,
        ];

        try {
            yield $activity->exportReport($input->tenantId, $input->actingUserId, $input->exportJobId);
        } catch (Throwable $exception) {
            yield $activity->finalizeReportExport($input->tenantId, $input->exportJobId);

            $logger->error('Report export workflow failed.', $context);

            throw $exception;
        }

        yield $activity->finalizeReportExport($input->tenantId, $input->exportJobId);

        $logger->info('Report export workflow finished.', $context);
    }
}
