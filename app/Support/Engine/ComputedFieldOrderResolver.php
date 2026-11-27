<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\FieldDependency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ComputedFieldOrderResolver
{
    /**
     * @param  list<string>  $changedFieldKeys
     * @return list<FieldDefinition>
     */
    public function orderFor(string $objectTypeId, array $changedFieldKeys = []): array
    {
        $fields = FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->get();

        return $this->orderWithin($objectTypeId, $fields, $this->edgesBetween($fields), $changedFieldKeys);
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return Collection<int, FieldDependency>
     */
    private function edgesBetween(Collection $fields): Collection
    {
        $fieldIds = $fields
            ->map(static fn (FieldDefinition $field): string => (string) $field->getKey())
            ->all();

        return FieldDependency::query()
            ->whereIn('rollup_field_id', $fieldIds)
            ->whereIn('depends_on_field_id', $fieldIds)
            ->get();
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @param  Collection<int, FieldDependency>  $edges
     * @param  list<string>  $changedFieldKeys
     * @return list<FieldDefinition>
     */
    public function orderWithin(
        string $objectTypeId,
        Collection $fields,
        Collection $edges,
        array $changedFieldKeys = [],
    ): array {
        $computedFields = $fields->filter(
            static fn (FieldDefinition $field): bool => $field->field_type === FieldType::Computed
        );

        if ($computedFields->isEmpty()) {
            return [];
        }

        $graph = $this->graphFor($fields, $computedFields, $edges);
        $analysis = $graph->analyse();
        $order = $analysis['order'];

        if ($order === null) {
            Log::warning('Skipped the formula evaluation order because the field dependency graph is cyclic.', [
                'object_type_id' => $objectTypeId,
                'cycle' => $analysis['cycle'],
            ]);

            return [];
        }

        $affected = $changedFieldKeys === [] ? null : $this->transitiveDependentsOf($graph, $order, $changedFieldKeys);
        $computedByKey = $computedFields->keyBy('key');

        /** @var list<FieldDefinition> $ordered */
        $ordered = [];

        foreach ($order as $key) {
            if ($affected !== null && !isset($affected[$key])) {
                continue;
            }

            $field = $computedByKey->get($key);

            if ($field instanceof FieldDefinition) {
                $ordered[] = $field;
            }
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $order
     * @param  list<string>  $changedFieldKeys
     * @return array<string, true>
     */
    private function transitiveDependentsOf(RollupGraph $graph, array $order, array $changedFieldKeys): array
    {
        $affected = array_fill_keys($changedFieldKeys, true);

        foreach ($order as $key) {
            if (isset($affected[$key])) {
                continue;
            }

            foreach ($graph->dependenciesOf($key) as $dependency) {
                if (isset($affected[$dependency])) {
                    $affected[$key] = true;

                    break;
                }
            }
        }

        return $affected;
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @param  Collection<int, FieldDefinition>  $computedFields
     * @param  Collection<int, FieldDependency>  $edges
     */
    private function graphFor(Collection $fields, Collection $computedFields, Collection $edges): RollupGraph
    {
        /** @var array<string, string> $keyById */
        $keyById = $fields
            ->mapWithKeys(static fn (FieldDefinition $field): array => [(string) $field->getKey() => $field->key])
            ->all();

        $graph = new RollupGraph;

        foreach ($computedFields as $field) {
            $graph->addNode($field->key);
        }

        foreach ($edges as $edge) {
            if ($edge->relationship_type_id !== null) {
                continue;
            }

            if (!isset($keyById[$edge->rollup_field_id], $keyById[$edge->depends_on_field_id])) {
                continue;
            }

            $graph->addEdge($keyById[$edge->rollup_field_id], $keyById[$edge->depends_on_field_id]);
        }

        return $graph;
    }
}
