<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\PresenceVerifierInterface;

class FakePresenceVerifier implements PresenceVerifierInterface
{
    /**
     * @var array<string, list<string>>
     */
    private array $rows = [];

    /**
     * @param  array<string, list<string>>  $rows
     */
    public static function holding(array $rows): self
    {
        $verifier = new self;
        $verifier->rows = $rows;

        Validator::getFacadeRoot()->setPresenceVerifier($verifier);

        return $verifier;
    }

    /**
     * @param  string  $collection
     * @param  string  $column
     * @param  string  $value
     * @param  int|string|null  $excludeId
     * @param  string|null  $idColumn
     * @param  array<int, array<int, mixed>>  $extra
     */
    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = []): int
    {
        return in_array((string) $value, $this->rows[$collection] ?? [], true) ? 1 : 0;
    }

    /**
     * @param  string  $collection
     * @param  string  $column
     * @param  array<int, mixed>  $values
     * @param  array<int, array<int, mixed>>  $extra
     */
    public function getMultiCount($collection, $column, array $values, array $extra = []): int
    {
        $known = $this->rows[$collection] ?? [];

        return count(array_intersect(array_map(strval(...), $values), $known));
    }
}
