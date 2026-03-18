<?php

declare(strict_types=1);

namespace Nubos\Init;

use Illuminate\Support\ServiceProvider;
use Nubos\Init\Console\InitCommand;

class NubosInitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InitCommand::class,
            ]);
        }
    }
}
