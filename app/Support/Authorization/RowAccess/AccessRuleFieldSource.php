<?php

declare(strict_types=1);

namespace App\Support\Authorization\RowAccess;

use App\Models\FieldDefinition;
use App\Support\Engine\SystemFilterFields;
use Illuminate\Support\Collection;

class AccessRuleFieldSource
{
    public function __construct(private readonly SystemFilterFields $systemFields) {}

    /**
     * @return Collection<int, FieldDefinition>
     */
    public function filterableFieldsOf(string $objectTypeId): Collection
    {
        return FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->where('is_filterable', true)
            ->get()
            ->toBase()
            ->concat($this->systemFields->all($objectTypeId));
    }
}
