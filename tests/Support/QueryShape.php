<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Throwable;

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
        } catch (Throwable $thrown) {
            $exception = $thrown;

            while ($exception !== null && !$exception instanceof QueryException) {
                $exception = $exception->getPrevious();
            }

            if (!$exception instanceof QueryException) {
                throw $thrown;
            }

            /** @var list<mixed> $bindings */
            $bindings = array_values($exception->getBindings());

            return new self($exception->getSql(), $bindings);
        }

        return null;
    }

    public function isScopedToTenant(string $table, string $tenantId): bool
    {
        return $this->hasBinding($tenantId)
            && $this->hasConjunctiveCondition($table, 'tenant_id');
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
        foreach ($this->columnReferences($table, 'deleted_at') as $column) {
            if (str_contains($this->sql, "{$column} is null")) {
                return true;
            }
        }

        return false;
    }

    public function hasColumnCondition(string $table, string $column): bool
    {
        return str_contains($this->sql, "\"{$table}\".\"{$column}\" =")
            || str_contains($this->sql, "\"{$table}\".\"{$column}\" in");
    }

    public function hasConjunctiveCondition(string $table, string $column): bool
    {
        foreach ($this->columnReferences($table, $column) as $reference) {
            foreach (["{$reference} =", "{$reference} in"] as $needle) {
                $offset = 0;

                while (($position = strpos($this->sql, $needle, $offset)) !== false) {
                    if (!$this->isOrCombinedAt($position)) {
                        return true;
                    }

                    $offset = $position + 1;
                }
            }
        }

        return false;
    }

    public function hasBinding(mixed $value): bool
    {
        return in_array($value, $this->bindings, true);
    }

    public function targets(string $table): bool
    {
        return str_contains($this->sql, "from \"{$table}\"");
    }

    /**
     * @return list<string>
     */
    private function columnReferences(string $table, string $column): array
    {
        $references = ["\"{$table}\".\"{$column}\""];
        $isSingleTableQuery = str_starts_with($this->sql, "select * from \"{$table}\" where ")
            && substr_count($this->sql, 'select ') === 1
            && !str_contains($this->sql, ' join ');

        if ($isSingleTableQuery) {
            $references[] = "\"{$column}\"";
        }

        return $references;
    }

    private function isOrCombinedAt(int $position): bool
    {
        $start = $position;
        $end = $position;

        while (true) {
            $depth = 0;

            while ($start > 0) {
                $character = $this->sql[$start - 1];

                if ($character === ')') {
                    $depth++;
                } elseif ($character === '(') {
                    if ($depth === 0) {
                        break;
                    }

                    $depth--;
                }

                $start--;
            }

            $depth = 0;
            $length = strlen($this->sql);

            while ($end < $length) {
                $character = $this->sql[$end];

                if ($character === '(') {
                    $depth++;
                } elseif ($character === ')') {
                    if ($depth === 0) {
                        break;
                    }

                    $depth--;
                }

                $end++;
            }

            $group = substr($this->sql, $start, $end - $start);

            if (preg_match('/\sor\s/i', $this->outermostLevelOf($group)) === 1) {
                return true;
            }

            if ($start === 0 || str_starts_with(ltrim($group), 'select ')) {
                return false;
            }

            $start--;
            $end++;
        }
    }

    private function outermostLevelOf(string $group): string
    {
        $depth = 0;
        $outermost = '';

        foreach (str_split($group) as $character) {
            if ($character === '(') {
                $depth++;

                continue;
            }

            if ($character === ')') {
                $depth--;

                continue;
            }

            if ($depth === 0) {
                $outermost .= $character;
            }
        }

        return $outermost;
    }
}
