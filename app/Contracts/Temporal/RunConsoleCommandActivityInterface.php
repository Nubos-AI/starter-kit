<?php

declare(strict_types=1);

namespace App\Contracts\Temporal;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'PeriodicScans.')]
interface RunConsoleCommandActivityInterface
{
    #[ActivityMethod(name: 'runConsoleCommand')]
    public function runConsoleCommand(string $signature): void;
}
