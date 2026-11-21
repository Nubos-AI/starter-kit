<?php

declare(strict_types=1);

namespace App\Contracts\Temporal;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'PeriodicScans.')]
interface RunConsoleCommandActivityInterface
{
    /**
     * @return list<string>
     */
    #[ActivityMethod(name: 'periodicCommands')]
    public function periodicCommands(): array;

    #[ActivityMethod(name: 'runConsoleCommand')]
    public function runConsoleCommand(string $signature): void;
}
