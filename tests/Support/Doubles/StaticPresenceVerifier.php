<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Illuminate\Validation\Factory;
use Illuminate\Validation\PresenceVerifierInterface;

class StaticPresenceVerifier implements PresenceVerifierInterface
{
    /**
     * @var list<array{table: string, column: string, value: mixed}>
     */
    public array $askedFor = [];

    /**
     * @param  array<string, list<string>>  $existingValuesByTable
     */
    public function __construct(private readonly array $existingValuesByTable = []) {}

    /**
     * @param  array<string, list<string>>  $existingValuesByTable
     */
    public static function install(array $existingValuesByTable): self
    {
        $verifier = new self($existingValuesByTable);

        /** @var Factory $factory */
        $factory = app('validator');
        $factory->setPresenceVerifier($verifier);

        return $verifier;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = []): int
    {
        $this->askedFor[] = ['table' => $collection, 'column' => $column, 'value' => $value];

        return in_array($value, $this->existingValuesByTable[$collection] ?? [], true) ? 1 : 0;
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, mixed>  $extra
     */
    public function getMultiCount($collection, $column, array $values, array $extra = []): int
    {
        $count = 0;

        foreach ($values as $value) {
            $count += $this->getCount($collection, $column, $value);
        }

        return $count;
    }

    public function setConnection($connection): void {}
}
