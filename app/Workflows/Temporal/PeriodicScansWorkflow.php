<?php

declare(strict_types=1);

namespace App\Workflows\Temporal;

use App\Contracts\Temporal\PeriodicScansWorkflowInterface;
use App\Contracts\Temporal\RunConsoleCommandActivityInterface;
use App\Enums\Temporal\PeriodicScanCommand;
use App\Support\Temporal\RecordRetryOptions;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;

class PeriodicScansWorkflow implements PeriodicScansWorkflowInterface
{
    public function scan(): Generator
    {
        $runner = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::seconds(300))
            ->withRetryOptions(RecordRetryOptions::make())
            ->build(RunConsoleCommandActivityInterface::class);

        foreach ([...array_column(PeriodicScanCommand::cases(), 'value'), ...config('modules.periodic_commands', [])] as $command) {
            yield $runner->runConsoleCommand($command);
        }
    }
}
