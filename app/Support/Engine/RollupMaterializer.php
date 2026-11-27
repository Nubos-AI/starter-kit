<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RollupMaterialization;
use App\Exceptions\Engine\IncompleteRollupChildSetException;
use App\Handlers\CustomFields\RollupFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

class RollupMaterializer
{
    public function __construct(
        private readonly RollupFieldHandler $handler,
        private readonly RollupChildResolver $childResolver,
        private readonly RecordDataPatcher $patcher,
    ) {}

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     */
    public function hasChildLinks(CustomRecord $record, Collection $fields): bool
    {
        $relationshipTypeIds = $fields
            ->map(fn (FieldDefinition $field): string => $this->childResolver->relationshipTypeIdOf($field))
            ->unique()
            ->values()
            ->all();

        return DB::table('record_links')
            ->where('record_links.tenant_id', (string) $record->getAttribute('tenant_id'))
            ->where('record_links.from_record_id', (string) $record->getKey())
            ->when(
                !in_array('', $relationshipTypeIds, true),
                fn ($query) => $query->whereIn('record_links.relationship_type_id', $relationshipTypeIds),
            )
            ->exists();
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     *
     * @throws JsonException
     */
    public function materialize(CustomRecord $record, Collection $fields): RollupMaterialization
    {
        /** @var list<FieldDefinition> $resolved */
        $resolved = [];

        /** @var list<FieldDefinition> $incomplete */
        $incomplete = [];

        /** @var array<string, array{sql: string, bindings: list<mixed>}> $expressions */
        $expressions = [];

        foreach ($fields as $field) {
            try {
                $aggregate = $this->handler->aggregateQuery($field, $record);
            } catch (IncompleteRollupChildSetException) {
                $incomplete[] = $field;

                continue;
            }

            $resolved[] = $field;
            $expressions[$field->key] = [
                'sql' => "({$aggregate->toSql()})",
                'bindings' => $aggregate->getBindings(),
            ];
        }

        $previous = $record->data ?? [];

        if (!$this->patcher->merge($record, $expressions)) {
            return new RollupMaterialization([], $incomplete);
        }

        return new RollupMaterialization($this->changedFields($previous, $record->data ?? [], $resolved), $incomplete);
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     *
     * @throws JsonException
     */
    public function materializeEmpty(CustomRecord $record, Collection $fields): RollupMaterialization
    {
        /** @var list<FieldDefinition> $resolved */
        $resolved = [];

        /** @var list<FieldDefinition> $incomplete */
        $incomplete = [];

        /** @var array<string, int|null> $emptyValues */
        $emptyValues = [];

        foreach ($fields as $field) {
            try {
                $this->childResolver->assertResolvable($field);
            } catch (IncompleteRollupChildSetException) {
                $incomplete[] = $field;

                continue;
            }

            $resolved[] = $field;
            $emptyValues[$field->key] = $this->handler->emptyValue($field);
        }

        if ($this->changedFields($record->data ?? [], $emptyValues, $resolved) === []) {
            return new RollupMaterialization([], $incomplete);
        }

        $previous = $record->data ?? [];

        if (!$this->patcher->overwrite($record, $emptyValues)) {
            return new RollupMaterialization([], $incomplete);
        }

        return new RollupMaterialization($this->changedFields($previous, $record->data ?? [], $resolved), $incomplete);
    }

    /**
     * @param  array<array-key, mixed>  $previous
     * @param  array<array-key, mixed>  $current
     * @param  list<FieldDefinition>  $fields
     * @return list<FieldDefinition>
     */
    private function changedFields(array $previous, array $current, array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn (FieldDefinition $field): bool => $this->hasValueChanged($previous[$field->key] ?? null, $current[$field->key] ?? null),
        ));
    }

    private function hasValueChanged(mixed $previous, mixed $current): bool
    {
        if (!is_int($current) && !is_float($current)) {
            return $previous !== null;
        }

        if (!is_numeric($previous)) {
            return true;
        }

        return (float) $previous !== (float) $current;
    }
}
