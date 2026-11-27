<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Modules\ModuleStateFingerprint;
use Illuminate\Support\Facades\Log;
use Keepsuit\LaravelTemporal\Commands\WorkCommand;
use Keepsuit\LaravelTemporal\Support\RoadRunnerBinaryHelper;
use Keepsuit\LaravelTemporal\Support\ServerProcessInspector;
use Keepsuit\LaravelTemporal\Support\ServerStateFile;

class WorkTemporalCommand extends WorkCommand
{
    private ?string $moduleState = null;

    public function __construct(private readonly ModuleStateFingerprint $fingerprint)
    {
        parent::__construct();
    }

    public function handle(
        ServerStateFile $serverStateFile,
        ServerProcessInspector $inspector,
        RoadRunnerBinaryHelper $roadRunnerBinaryHelper,
    ): ?int {
        $interval = max(1, (int) config('modules.worker_state_check_seconds'));
        $this->moduleState = $this->fingerprint->current();

        $this->trap(SIGALRM, function () use ($interval): void {
            if (!$this->stopOnModuleChange()) {
                pcntl_alarm($interval);
            }
        });
        pcntl_alarm($interval);

        return parent::handle($serverStateFile, $inspector, $roadRunnerBinaryHelper);
    }

    protected function stopOnModuleChange(): bool
    {
        $state = $this->fingerprint->current();

        if ($state === null) {
            return false;
        }

        if ($this->moduleState === null || $state === $this->moduleState) {
            $this->moduleState = $state;

            return false;
        }

        $this->info('The modules have changed. The worker stops and restarts with the new state.');
        Log::info('The installed or registered modules changed; the Temporal worker stops so its container restarts it.');
        $this->handleSignal(SIGTERM);

        return true;
    }
}
