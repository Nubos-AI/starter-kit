<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Authorization\FieldVisibilityResolver;
use Illuminate\Support\Collection;

class RecordStateConditionEvaluator
{
    public function __construct(
        private readonly RecordFilterCompiler $compiler,
        private readonly SystemFilterFields $systemFields,
    ) {}

    /**
     * @param  array<string, mixed>  $filterDefinition
     */
    public function matches(CustomRecord $record, array $filterDefinition): bool
    {
        if ($filterDefinition === []) {
            return true;
        }

        $objectType = ObjectType::query()->whereKey($record->object_type_id)->firstOrFail();

        $query = CustomRecord::query()->whereKey($record->getKey());

        $this->compiler->applyTree(
            $query,
            $this->filterableFields($objectType),
            $filterDefinition,
            FieldVisibilityResolver::forRequest(),
            $objectType,
        );

        return $query->exists();
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    private function filterableFields(ObjectType $objectType): Collection
    {
        $fields = FieldDefinition::query()
            ->where('object_type_id', $objectType->getKey())
            ->where('is_filterable', true)
            ->get();

        $missing = $this->systemFields
            ->all((string) $objectType->getKey())
            ->reject(fn (FieldDefinition $field): bool => $fields->contains('key', $field->key));

        return $fields->concat($missing)->values();
    }
}
