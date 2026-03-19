<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TenantDatabaseManager
{
    public function createDatabase(Tenant $tenant): void
    {
        $this->assertValidDatabaseName($tenant->database);

        $driver = $this->getDatabaseDriver();

        match ($driver) {
            'mysql' => DB::statement("CREATE DATABASE IF NOT EXISTS `{$tenant->database}`"),
            'pgsql' => $this->createPostgresDatabase($tenant->database),
            default => throw new RuntimeException("Unsupported database driver: {$driver}"),
        };

        $this->migrate($tenant);
    }

    public function dropDatabase(Tenant $tenant): void
    {
        $this->assertValidDatabaseName($tenant->database);

        $driver = $this->getDatabaseDriver();

        match ($driver) {
            'mysql' => DB::statement("DROP DATABASE IF EXISTS `{$tenant->database}`"),
            'pgsql' => $this->dropPostgresDatabase($tenant->database),
            default => throw new RuntimeException("Unsupported database driver: {$driver}"),
        };
    }

    public function migrate(Tenant $tenant): void
    {
        $migrationPath = 'database/migrations/tenant';

        if (!is_dir(base_path($migrationPath))) {
            return;
        }

        $tenant->configureDatabaseConnection();

        try {
            $exitCode = Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => $migrationPath,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException("Migration failed for tenant database: {$tenant->database}");
            }
        } catch (Throwable $e) {
            Log::error("Tenant migration failed for {$tenant->database}: {$e->getMessage()}");

            throw $e;
        }
    }

    public function migrateAll(): void
    {
        Tenant::query()->each(fn (Tenant $tenant) => $this->migrate($tenant));
    }

    private function createPostgresDatabase(string $name): void
    {
        $exists = DB::select(
            'SELECT 1 FROM pg_database WHERE datname = ?',
            [$name],
        );

        if ($exists === []) {
            DB::statement("CREATE DATABASE \"{$name}\"");
        }
    }

    private function dropPostgresDatabase(string $name): void
    {
        DB::statement("DROP DATABASE IF EXISTS \"{$name}\"");
    }

    private function getDatabaseDriver(): string
    {
        /** @var string|null $tenantDriver */
        $tenantDriver = config('database.connections.tenant.driver');

        if ($tenantDriver !== null) {
            return $tenantDriver;
        }

        /** @var string $default */
        $default = config('database.default');

        /** @var string $driver */
        $driver = config("database.connections.{$default}.driver");

        return $driver;
    }

    private function assertValidDatabaseName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid database name: {$name}");
        }
    }
}
