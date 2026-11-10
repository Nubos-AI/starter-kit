<?php

declare(strict_types=1);

namespace App\Activities\Temporal;

use App\Contracts\Temporal\RunConsoleCommandActivityInterface;
use App\Enums\Temporal\PeriodicScanCommand;
use App\Exceptions\Temporal\PeriodicScanFailedException;
use App\Traits\Temporal\DescribesWorkflowExecution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class RunConsoleCommandActivity implements RunConsoleCommandActivityInterface
{
    use DescribesWorkflowExecution;

    /**
     * @return list<string>
     */
    public function periodicCommands(): array
    {
        $moduleCommands = config('modules.periodic_commands', []);

        return [
            ...array_column(PeriodicScanCommand::cases(), 'value'),
            ...array_values(array_filter(is_array($moduleCommands) ? $moduleCommands : [], is_string(...))),
        ];
    }

    /**
     * @throws Throwable
     */
    public function runConsoleCommand(string $signature): void
    {
        if (!in_array($signature, $this->periodicCommands(), true)) {
            throw new InvalidArgumentException("Console command [{$signature}] is not a permitted periodic scan.");
        }

        $execution = $this->workflowExecution();

        try {
            $exitCode = Artisan::call($signature);
        } catch (Throwable $exception) {
            Log::error('Periodic scan command crashed.', ['command' => $signature, 'exception' => $exception::class, ...$execution]);

            throw $exception;
        }

        $context = ['command' => $signature, 'exit_code' => $exitCode, ...$execution];

        if ($exitCode !== Command::SUCCESS) {
            Log::error('Periodic scan command failed.', $context);

            throw PeriodicScanFailedException::for($signature, $exitCode);
        }

        Log::info('Periodic scan command finished.', $context);
    }
}
