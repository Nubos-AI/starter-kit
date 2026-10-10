<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\FieldDefinition;
use App\Support\Authorization\RowAccess\AccessRuleFieldSource;
use Illuminate\Support\Collection;

class StaticAccessRuleFieldSource extends AccessRuleFieldSource
{
    /**
     * @var list<string>
     */
    public array $askedFor = [];

    /**
     * @param  array<string, Collection<int, FieldDefinition>>  $byObjectType
     */
    public function __construct(private array $byObjectType = []) {}

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function filterableFieldsOf(string $objectTypeId): Collection
    {
        $this->askedFor[] = $objectTypeId;

        return $this->byObjectType[$objectTypeId] ?? new Collection;
    }
}
