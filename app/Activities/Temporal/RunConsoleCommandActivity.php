<?php

declare(strict_types=1);

namespace App\Activities\Temporal;

use App\Contracts\Temporal\RunConsoleCommandActivityInterface;
use App\Enums\Temporal\PeriodicScanCommand;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

class RunConsoleCommandActivity implements RunConsoleCommandActivityInterface
{
    public function runConsoleCommand(string $signature): void
    {
        if (PeriodicScanCommand::tryFrom($signature) === null && !in_array($signature, config('modules.periodic_commands', []), true)) {
            throw new InvalidArgumentException("Console command [{$signature}] is not a permitted periodic scan.");
        }

        Artisan::call($signature);
    }
}
