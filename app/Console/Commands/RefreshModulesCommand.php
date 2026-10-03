<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Octane\FrankenPhp\ServerProcessInspector as FrankenPhpInspector;
use Laravel\Octane\RoadRunner\ServerProcessInspector as RoadRunnerInspector;
use Laravel\Octane\Swoole\ServerProcessInspector as SwooleInspector;
use Laravel\Octane\Swoole\SwooleExtension;

class RefreshModulesCommand extends Command
{
    protected $signature = 'modules:refresh';

    protected $description = 'Refresh routes and running web workers after Composer changes';

    public function handle(
        SwooleInspector $swoole,
        RoadRunnerInspector $roadrunner,
        FrankenPhpInspector $frankenphp,
        SwooleExtension $swooleExtension,
    ): int {
        foreach (['route:clear', 'event:clear', 'view:clear'] as $command) {
            if ($this->callSilent($command) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        if ($this->callSilent('wayfinder:generate', ['--with-form' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $inspector = match (config('octane.server')) {
            'swoole' => $swooleExtension->isInstalled() ? $swoole : null,
            'roadrunner' => $roadrunner,
            'frankenphp' => $frankenphp,
            default => null,
        };

        if ($inspector?->serverIsRunning()) {
            $inspector->reloadServer();
        }

        return self::SUCCESS;
    }
}
