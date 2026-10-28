<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Closure;
use Generator;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StaticQueryConnection extends PostgresConnection implements ConnectionResolverInterface
{
    /**
     * @var list<array{sql: string, bindings: list<mixed>}>
     */
    public array $queries = [];

    /**
     * @var list<array{sql: string, bindings: list<mixed>}>
     */
    public array $writtenStatements = [];

    public int $openedTransactions = 0;

    /**
     * @var Closure(string, list<mixed>): int|null
     */
    private ?Closure $writes = null;

    private static ?ConnectionResolverInterface $previousResolver = null;

    /**
     * @param  Closure(string, list<mixed>): list<array<string, mixed>>  $answer
     */
    public function __construct(private readonly Closure $answer)
    {
        parent::__construct(
            static fn (): never => throw new RuntimeException('A database free test must never open a connection.'),
            'static',
            '',
            ['driver' => 'pgsql', 'name' => 'static'],
        );
    }

    /**
     * @param  Closure(string, list<mixed>): list<array<string, mixed>>  $answer
     */
    public static function install(Closure $answer, ?Closure $writes = null): self
    {
        $connection = new self($answer);
        $connection->writes = $writes;

        self::$previousResolver ??= Model::getConnectionResolver();

        Model::setConnectionResolver($connection);
        app()->instance('db', $connection);
        DB::clearResolvedInstances();

        return $connection;
    }

    public static function uninstall(): void
    {
        app()->forgetInstance('db');
        DB::clearResolvedInstances();

        if (self::$previousResolver instanceof ConnectionResolverInterface) {
            Model::setConnectionResolver(self::$previousResolver);

            self::$previousResolver = null;
        }
    }

    /**
     * Transactions themselves are framework behaviour, so a database free test runs
     * their body inline: the guards inside become reachable while every statement
     * still travels through the recording methods below.
     *
     * @template TReturn
     *
     * @param  Closure(static): TReturn  $callback
     * @param  int  $attempts
     * @return TReturn
     */
    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        $this->openedTransactions++;

        return $callback($this);
    }

    public function beginTransaction(): void
    {
        $this->openedTransactions++;
    }

    public function commit(): void {}

    public function rollBack($toLevel = null): void {}

    public function transactionLevel(): int
    {
        return 0;
    }

    /**
     * What a codepath defers until after the commit is kept, not run: the test decides
     * whether it wants to look at it.
     *
     * @var list<callable>
     */
    public array $deferredAfterCommit = [];

    public function afterCommit($callback): void
    {
        $this->deferredAfterCommit[] = $callback;
    }

    public function connection($name = null): self
    {
        return $this;
    }

    public function getDefaultConnection(): string
    {
        return 'static';
    }

    public function setDefaultConnection($name): void {}

    /**
     * @param  string  $query
     * @param  array<int|string, mixed>  $bindings
     * @param  bool  $useReadPdo
     * @param  array<int|string, mixed>  $fetchUsing
     * @return list<object>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        $this->queries[] = ['sql' => $query, 'bindings' => array_values($bindings)];

        return array_map(
            static fn (array $row): object => (object) $row,
            ($this->answer)($query, array_values($bindings)),
        );
    }

    /**
     * @param  string  $query
     * @param  array<int|string, mixed>  $bindings
     * @param  bool  $useReadPdo
     * @param  array<int|string, mixed>  $fetchUsing
     * @return Generator<int, object>
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): Generator
    {
        yield from $this->select($query, $bindings, $useReadPdo, $fetchUsing);
    }

    /**
     * @param  string  $query
     * @param  array<int|string, mixed>  $bindings
     */
    public function statement($query, $bindings = []): bool
    {
        $this->recordWrite($query, array_values($bindings));

        return true;
    }

    /**
     * @param  string  $query
     * @param  array<int|string, mixed>  $bindings
     */
    public function affectingStatement($query, $bindings = []): int
    {
        return $this->recordWrite($query, array_values($bindings));
    }

    /**
     * A test that hands over a write answer states how many rows the statement would
     * touch, which is what the code after the write reacts to (stale versions, claims
     * that lost the race). Without such an answer a write stays a defect.
     *
     * @param  list<mixed>  $bindings
     */
    private function recordWrite(string $query, array $bindings): int
    {
        if (!$this->writes instanceof Closure) {
            throw new RuntimeException('A database free test must never write to a connection.');
        }

        $this->writtenStatements[] = ['sql' => $query, 'bindings' => $bindings];

        return ($this->writes)($query, $bindings);
    }

    /**
     * @return list<string>
     */
    public function writtenSqlOf(string $table): array
    {
        return array_values(array_map(
            static fn (array $query): string => $query['sql'],
            array_filter(
                $this->writtenStatements,
                static fn (array $query): bool => str_contains($query['sql'], "\"{$table}\""),
            ),
        ));
    }

    /**
     * @return list<string>
     */
    public function sqlOf(string $table): array
    {
        return array_values(array_map(
            static fn (array $query): string => $query['sql'],
            array_filter(
                $this->queries,
                static fn (array $query): bool => str_contains($query['sql'], "from \"{$table}\""),
            ),
        ));
    }
}
