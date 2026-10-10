<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SchemaShape
{
    /**
     * @param  list<string>  $statements
     */
    public function __construct(public array $statements) {}

    public static function ofMigration(string $path, string $direction = 'up'): self
    {
        $migration = require base_path($path);

        if (!$migration instanceof Migration) {
            throw new RuntimeException("[{$path}] does not return a migration instance.");
        }

        $logged = DB::connection()->pretend(static function () use ($migration, $direction): void {
            $migration->{$direction}();
        });

        /** @var list<string> $statements */
        $statements = array_map(
            static fn (array $entry): string => (string) $entry['query'],
            $logged,
        );

        return new self($statements);
    }

    /**
     * @param  Closure(Blueprint): void  $definition
     */
    public static function ofBlueprint(string $table, Closure $definition, bool $creating = true): self
    {
        $blueprint = new Blueprint(DB::connection(), $table);

        if ($creating) {
            $blueprint->create();
        }

        $definition($blueprint);

        return new self(array_values($blueprint->toSql()));
    }

    public function statementMatching(string $needle): ?string
    {
        foreach ($this->statements as $statement) {
            if (str_contains($statement, $needle)) {
                return $statement;
            }
        }

        return null;
    }

    public function has(string $needle): bool
    {
        return $this->statementMatching($needle) !== null;
    }

    public function createStatementFor(string $table): string
    {
        $statement = $this->statementMatching("create table \"{$table}\" (");

        if ($statement === null) {
            throw new RuntimeException("No create statement for table [{$table}].");
        }

        return $statement;
    }

    /**
     * @return list<string>
     */
    public function columnsOf(string $table): array
    {
        preg_match_all('/[(,]\s*"([a-z0-9_]+)"\s/i', $this->createStatementFor($table), $matches);

        return $matches[1];
    }

    public function columnDefinition(string $table, string $column): ?string
    {
        preg_match(
            '/[(,]\s*"'.preg_quote($column, '/').'" ([^,)]*(?:\([^)]*\))?[^,]*)/i',
            $this->createStatementFor($table),
            $matches,
        );

        return $matches[1] ?? null;
    }

    public function hasForeignKey(string $table, string $column, string $referencedTable, string $onDelete): bool
    {
        return $this->has(
            "alter table \"{$table}\" add constraint \"{$table}_{$column}_foreign\" "
            ."foreign key (\"{$column}\") references \"{$referencedTable}\" (\"id\") on delete {$onDelete}",
        );
    }

    /**
     * @param  list<string>  $columns
     */
    public function hasPartialUniqueIndex(string $name, string $table, array $columns, string $predicate): bool
    {
        $expected = sprintf(
            'CREATE UNIQUE INDEX %s ON %s (%s) WHERE %s',
            $name,
            $table,
            implode(', ', $columns),
            $predicate,
        );

        return in_array($expected, $this->statements, true);
    }
}
