<?php

declare(strict_types=1);

namespace App\Workflows\Temporal;

use App\Contracts\Temporal\PeriodicScansWorkflowInterface;
use App\Contracts\Temporal\RunConsoleCommandActivityInterface;
use App\Support\Temporal\RecordRetryOptions;
use Carbon\CarbonInterval;
use Generator;
use Keepsuit\LaravelTemporal\Facade\Temporal;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow;

class PeriodicScansWorkflow implements PeriodicScansWorkflowInterface
{
    public function scan(): Generator
    {
        $runner = Temporal::newActivity()
            ->withStartToCloseTimeout(CarbonInterval::seconds(300))
            ->withRetryOptions(RecordRetryOptions::make())
            ->build(RunConsoleCommandActivityInterface::class);

        $logger = Workflow::getLogger();
        $execution = Workflow::getInfo()->execution;
        $run = ['workflow_id' => $execution->getID(), 'run_id' => $execution->getRunID()];

        $listed = yield $runner->periodicCommands();
        $commands = array_values(array_filter(is_array($listed) ? $listed : [], is_string(...)));
        $failedCommands = [];
        $firstFailure = null;

        foreach ($commands as $command) {
            try {
                yield $runner->runConsoleCommand($command);
            } catch (ActivityFailure $failure) {
                $failedCommands[] = $command;
                $firstFailure ??= $failure;

                $logger->error('Periodic scan failed; the remaining scans still run.', ['command' => $command, ...$run]);
            }
        }

        if ($firstFailure instanceof ActivityFailure) {
            $logger->error('Periodic scans finished with failures.', ['commands' => count($commands), 'failed' => $failedCommands, ...$run]);

            throw $firstFailure;
        }

        $logger->info('Periodic scans finished.', ['commands' => count($commands), ...$run]);
    }
}
