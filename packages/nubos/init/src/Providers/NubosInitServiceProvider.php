<?php

declare(strict_types=1);

namespace Nubos\Init\Providers;

use Illuminate\Support\ServiceProvider;
use Nubos\Init\Console\NubosInitCommand;

final class NubosInitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                NubosInitCommand::class,
            ]);
        }
    }
}
