<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery\MockInterface;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => (string) config('app.default_locale')]);
    }

    protected function disconnectDatabaseConnections(): void
    {
        if ($this->app?->resolved('db') && $this->app->make('db') instanceof MockInterface) {
            return;
        }

        parent::disconnectDatabaseConnections();
    }
}
