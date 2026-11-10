<?php

declare(strict_types=1);

namespace App\Support\Authorization\RowAccess;

class EffectiveRecordAccessRules
{
    /**
     * @param  array<string, list<list<array<string, mixed>>>>  $byObjectType  Outer list = OR over team chains, inner list = AND within one chain.
     */
    public function __construct(private readonly array $byObjectType) {}

    public static function unrestricted(): self
    {
        return new self([]);
    }

    public function isUnrestricted(): bool
    {
        return $this->byObjectType === [];
    }

    /**
     * @return list<string>
     */
    public function objectTypeIds(): array
    {
        return array_keys($this->byObjectType);
    }

    /**
     * @return array<string, list<list<array<string, mixed>>>>
     */
    public function all(): array
    {
        return $this->byObjectType;
    }
}
