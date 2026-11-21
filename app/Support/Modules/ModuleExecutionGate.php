<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Closure;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ModuleExecutionGate
{
    public function __construct(private readonly ConnectionResolverInterface $database) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(string $module, Closure $operation): mixed
    {
        $connection = $this->database->connection();
        $connection->select('SELECT pg_advisory_lock_shared(hashtextextended(?, 0))', ['module:'.$module]);
        try {
            if (!$this->enabled($module)) {
                throw new RuntimeException(__('i18n.backend.support.modules.module_execution_gate.module_execution_is_disabled').$module);
            }

            return $operation();
        } finally {
            $connection->select('SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))', ['module:'.$module]);
        }
    }

    public function enabled(string $module): bool
    {
        return !$this->database->connection()->table('modules')->where('name', $module)->where('disabled', true)->exists();
    }

    public function disable(string $module): void
    {
        $this->database->connection()->table('modules')->upsert(
            [['id' => (string) Str::ulid(), 'name' => $module, 'disabled' => true]],
            ['name'], ['disabled'],
        );
    }

    /**
     * @throws Throwable
     */
    public function drain(string $module, Closure $operation): void
    {
        $connection = $this->database->connection();

        $connection->transaction(function () use ($connection, $module, $operation): void {
            $connection->statement("SET LOCAL lock_timeout = '60s'");
            $connection->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['module:'.$module]);
            $operation();
        });
    }
}
