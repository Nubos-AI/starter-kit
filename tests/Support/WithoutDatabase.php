<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Tests\Support\Doubles\UnreachableDatabaseConnector;

trait WithoutDatabase
{
    /**
     * @var list<string>
     */
    private array $databaseDrivers = ['mariadb', 'mysql', 'pgsql', 'sqlite', 'sqlsrv'];

    protected function setUpWithoutDatabase(): void
    {
        foreach ($this->databaseDrivers as $driver) {
            app()->instance("db.connector.{$driver}", new UnreachableDatabaseConnector);
        }

        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge((string) $connection);
        }
    }
}
