<?php

declare(strict_types=1);

namespace App\Workflows\Export;

use App\Contracts\Export\ExportRecordsActivityInterface;
use App\Contracts\Export\ExportWorkflowInterface;
use App\Contracts\Export\FinalizeExportActivityInterface;
use App\DTOs\Export\ExportData;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Throwable;

class ExportWorkflow implements ExportWorkflowInterface
{
    public function run(ExportData $input): Generator
    {
        $export = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(30))
            ->build(ExportRecordsActivityInterface::class);

        $finalize = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::minutes(2))
            ->build(FinalizeExportActivityInterface::class);

        try {
            yield $export->exportRecords($input->tenantId, $input->actingUserId, $input->exportJobId);
        } catch (Throwable $exception) {
            yield $finalize->finalizeExport($input->tenantId, $input->actingUserId, $input->exportJobId);

            throw $exception;
        }

        yield $finalize->finalizeExport($input->tenantId, $input->actingUserId, $input->exportJobId);
    }
}
