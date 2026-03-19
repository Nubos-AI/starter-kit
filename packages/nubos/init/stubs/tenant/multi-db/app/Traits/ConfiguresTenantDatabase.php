<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Facades\DB;

/**
 * @property string $database
 */
trait ConfiguresTenantDatabase
{
    public function configureDatabaseConnection(): void
    {
        $default = config('database.default');

        /** @var array<string, mixed> $baseConfig */
        $baseConfig = config("database.connections.{$default}");

        config([
            'database.connections.tenant' => array_merge($baseConfig, [
                'database' => $this->database,
            ]),
        ]);

        DB::purge('tenant');
        DB::reconnect('tenant');
    }
}
