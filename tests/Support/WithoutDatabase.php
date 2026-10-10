<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

trait WithoutDatabase
{
    private string $unreachableDatabaseHost = '127.0.0.1';

    private int $unreachableDatabasePort = 1;

    protected function setUpWithoutDatabase(): void
    {
        $this->severDatabaseConnection((string) config('database.default'));
    }

    protected function severDatabaseConnection(string $connection): void
    {
        Config::set("database.connections.{$connection}.host", $this->unreachableDatabaseHost);
        Config::set("database.connections.{$connection}.port", $this->unreachableDatabasePort);
        Config::set("database.connections.{$connection}.url", null);
        Config::set("database.connections.{$connection}.read", null);
        Config::set("database.connections.{$connection}.write", null);

        DB::purge($connection);
    }
}
