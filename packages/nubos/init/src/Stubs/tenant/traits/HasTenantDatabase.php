<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait HasTenantDatabase
{
    public function configureDatabaseConnection(): void
    {
        config([
            'database.connections.tenant' => [
                'driver' => 'pgsql',
                'host' => $this->db_host,
                'port' => $this->db_port,
                'database' => $this->db_database,
                'username' => $this->db_username,
                'password' => $this->db_password,
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ],
        ]);

        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    public function getTenantConnectionName(): string
    {
        return 'tenant';
    }
}
