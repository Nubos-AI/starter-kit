<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;

class QueryShape
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(public string $sql, public array $bindings) {}

    /**
     * @param  EloquentBuilder<covariant Model>|QueryBuilder|Relation<covariant Model, covariant Model, *>|Model|class-string<Model>  $query
     */
    public static function of(EloquentBuilder|QueryBuilder|Relation|Model|string $query): self
    {
        $builder = match (true) {
            is_string($query) => $query::query(),
            $query instanceof Model => $query->newQuery(),
            default => $query,
        };

        return new self($builder->toSql(), $builder->getBindings());
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    public static function attemptedBy(Closure $callback): ?self
    {
        try {
            $callback();
        } catch (QueryException $exception) {
            /** @var list<mixed> $bindings */
            $bindings = array_values($exception->getBindings());

            return new self($exception->getSql(), $bindings);
        }

        return null;
    }

    public function isScopedToTenant(string $table, string $tenantId): bool
    {
        return $this->hasColumnCondition($table, 'tenant_id')
            && $this->hasBinding($tenantId);
    }

    public function blocksEveryRow(): bool
    {
        return str_contains($this->sql, '1 = 0');
    }

    public function isKeyedTo(string $table, string $key): bool
    {
        return $this->hasColumnCondition($table, 'id')
            && $this->hasBinding($key);
    }

    public function hidesSoftDeleted(string $table): bool
    {
        return str_contains($this->sql, "\"{$table}\".\"deleted_at\" is null");
    }

    public function hasColumnCondition(string $table, string $column): bool
    {
        return str_contains($this->sql, "\"{$table}\".\"{$column}\" =")
            || str_contains($this->sql, "\"{$table}\".\"{$column}\" in");
    }

    public function hasBinding(mixed $value): bool
    {
        return in_array($value, $this->bindings, true);
    }

    public function targets(string $table): bool
    {
        return str_contains($this->sql, "from \"{$table}\"");
    }
}
