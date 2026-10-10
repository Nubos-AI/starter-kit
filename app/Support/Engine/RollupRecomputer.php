<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\RollupScope;
use App\Exceptions\Engine\IncompleteRollupChildSetException;
use App\Handlers\CustomFields\RollupFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\FieldDependency;
use App\Models\RecordLink;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use JsonException;

class RollupRecomputer
{
    public function __construct(
        private readonly RollupFieldHandler $handler,
        private readonly ComputedFieldWriter $computedWriter,
    ) {}

    /**
     * @param  array<int, string>  $changedFieldKeys
     *
     * @throws JsonException
     */
    public function recompute(string $tenantId, string $objectTypeId, string $recordId, array $changedFieldKeys = []): void
    {
        TenantContext::withTenantId($tenantId, function () use ($tenantId, $objectTypeId, $recordId, $changedFieldKeys): void {
            $this->cascade($tenantId, $objectTypeId, $recordId, $changedFieldKeys);
        });
    }

    /**
     * @throws JsonException
     */
    public function recomputeOwn(string $tenantId, string $objectTypeId, string $recordId): void
    {
        TenantContext::withTenantId($tenantId, function () use ($tenantId, $objectTypeId, $recordId): void {
            $record = CustomRecord::query()
                ->where('tenant_id', $tenantId)
                ->ofType($objectTypeId)
                ->whereKey($recordId)
                ->first();

            if ($record === null) {
                Log::warning('Skipped the own roll-up recompute because the target record is not resolvable.', [
                    'tenant_id' => $tenantId,
                    'object_type_id' => $objectTypeId,
                    'record_id' => $recordId,
                ]);

                return;
            }

            $changedFieldKeys = $this->materializeOwnRollups($record);

            if ($changedFieldKeys === []) {
                return;
            }

            $this->cascade($tenantId, $objectTypeId, $recordId, $changedFieldKeys);
        });
    }

    /**
     * @return list<string>
     */
    private function materializeOwnRollups(CustomRecord $record): array
    {
        $fields = FieldDefinition::query()
            ->where('object_type_id', $record->object_type_id)
            ->where('field_type', FieldType::Rollup)
            ->get();

        $previousValues = $record->data ?? [];

        /** @var list<string> $changedFieldKeys */
        $changedFieldKeys = [];

        /** @var list<string> $changedSubtreeFieldIds */
        $changedSubtreeFieldIds = [];

        foreach ($fields as $field) {
            $previous = $previousValues[$field->key] ?? null;

            try {
                $current = $this->handler->materialize($field, $record);
            } catch (IncompleteRollupChildSetException) {
                $this->warnIncompleteChildSet($field, $record);

                continue;
            }

            if (!$this->hasRollupValueChanged($previous, $current)) {
                continue;
            }

            $changedFieldKeys[] = $field->key;

            if ($field->rollupScope() === RollupScope::Subtree) {
                $changedSubtreeFieldIds[] = (string) $field->getKey();
            }
        }

        return array_values(array_unique([
            ...$changedFieldKeys,
            ...$this->subtreeSourceKeys($changedSubtreeFieldIds),
        ]));
    }

    /**
     * @param  list<string>  $rollupFieldIds
     * @return list<string>
     */
    private function subtreeSourceKeys(array $rollupFieldIds): array
    {
        if ($rollupFieldIds === []) {
            return [];
        }

        $keys = FieldDefinition::query()
            ->whereIn(
                'id',
                FieldDependency::query()
                    ->select('depends_on_field_id')
                    ->whereIn('rollup_field_id', $rollupFieldIds),
            )
            ->pluck('key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();

        return array_values($keys);
    }

    private function hasRollupValueChanged(mixed $previous, int|float|null $current): bool
    {
        if ($current === null) {
            return $previous !== null;
        }

        if (!is_numeric($previous)) {
            return true;
        }

        return (float) $previous !== (float) $current;
    }

    /**
     * @param  array<int, string>  $changedFieldKeys
     *
     * @throws JsonException
     */
    private function cascade(string $tenantId, string $objectTypeId, string $recordId, array $changedFieldKeys): void
    {
        /** @var list<array{record: string, type: string, keys: list<string>, depth: int}> $frontier */
        $frontier = [[
            'record' => $recordId,
            'type' => $objectTypeId,
            'keys' => array_values($changedFieldKeys),
            'depth' => 0,
        ]];

        /** @var list<string> $visited */
        $visited = [];
        $iterations = 0;
        $maxIterations = (int) config('engine.rollups.max_iterations');

        $budgetLimit = (int) config('engine.rollups.max_computed_fields_per_pass');
        $computedBudget = $budgetLimit;
        $isBudgetWarned = false;

        /** @var array<string, list<FieldDefinition>> $orderMemo */
        $orderMemo = [];

        while ($frontier !== []) {
            $frame = array_shift($frontier);

            if (++$iterations > $maxIterations) {
                $this->warnCascadeStopped($frame, $iterations, $maxIterations);

                break;
            }

            $frame['keys'] = $this->materializeFormulaFields($frame, $orderMemo, $computedBudget, $isBudgetWarned, $budgetLimit);

            foreach ($this->dependentsOf($frame['type'], $frame['keys']) as $dependency) {
                $rollupField = FieldDefinition::query()->whereKey($dependency->rollup_field_id)->first();

                if ($rollupField === null) {
                    continue;
                }

                if ($rollupField->field_type !== FieldType::Rollup) {
                    continue;
                }

                foreach ($this->parentsLinking($tenantId, $frame['record'], $dependency->relationship_type_id) as $parentId) {
                    $pair = $parentId.':'.$rollupField->getKey();

                    if (in_array($pair, $visited, true)) {
                        continue;
                    }

                    $visited[] = $pair;

                    $parent = CustomRecord::query()->whereKey($parentId)->first();

                    if ($parent === null) {
                        continue;
                    }

                    try {
                        $this->handler->materialize($rollupField, $parent);
                    } catch (IncompleteRollupChildSetException) {
                        $this->warnIncompleteChildSet($rollupField, $parent);

                        continue;
                    }

                    $frontier[] = [
                        'record' => (string) $parent->getKey(),
                        'type' => $parent->object_type_id,
                        'keys' => $this->frontierKeysFor($rollupField, $frame['keys']),
                        'depth' => $frame['depth'] + 1,
                    ];
                }
            }
        }
    }

    /**
     * @param  list<string>  $frameKeys
     * @return list<string>
     */
    private function frontierKeysFor(FieldDefinition $rollupField, array $frameKeys): array
    {
        if ($rollupField->rollupScope() !== RollupScope::Subtree) {
            return [$rollupField->key];
        }

        return array_values(array_unique([...$frameKeys, $rollupField->key]));
    }

    private function warnIncompleteChildSet(FieldDefinition $field, CustomRecord $record): void
    {
        $config = is_array($field->config) ? $field->config : [];
        $relationshipTypeId = $config['relationship_type_id'] ?? null;

        Log::warning('Skipped a roll-up materialisation because the child set is not completely resolvable; the previous value stays in place.', [
            'tenant_id' => (string) $record->getAttribute('tenant_id'),
            'record_id' => (string) $record->getKey(),
            'field_id' => (string) $field->getKey(),
            'relationship_type_id' => is_string($relationshipTypeId) ? $relationshipTypeId : null,
        ]);
    }

    /**
     * @param  array{record: string, type: string, keys: list<string>, depth: int}  $frame
     */
    private function warnCascadeStopped(array $frame, int $iterations, int $maxIterations): void
    {
        Log::warning('Stopped the roll-up cascade at a safety limit; ancestors beyond the stopped frame keep a stale roll-up value.', [
            'object_type_id' => $frame['type'],
            'record_id' => $frame['record'],
            'depth' => $frame['depth'],
            'iterations' => $iterations,
            'max_iterations' => $maxIterations,
        ]);
    }

    /**
     * @param  array{record: string, type: string, keys: list<string>, depth: int}  $frame
     * @param  array<string, list<FieldDefinition>>  $orderMemo
     * @return list<string>
     *
     * @throws JsonException
     */
    private function materializeFormulaFields(array $frame, array &$orderMemo, int &$budget, bool &$isBudgetWarned, int $budgetLimit): array
    {
        $memoKey = $frame['type'].'|'.implode(',', $frame['keys']);
        $order = $orderMemo[$memoKey] ??= $this->computedWriter->orderFor($frame['type'], $frame['keys']);

        if ($order === []) {
            return $frame['keys'];
        }

        if ($budget <= 0) {
            $this->warnBudgetExhausted($frame, $budgetLimit, $isBudgetWarned);

            return $frame['keys'];
        }

        $record = CustomRecord::query()->whereKey($frame['record'])->first();

        if ($record === null) {
            return $frame['keys'];
        }

        $keys = $frame['keys'];

        foreach ($order as $field) {
            if ($budget <= 0) {
                $this->warnBudgetExhausted($frame, $budgetLimit, $isBudgetWarned);

                break;
            }

            $budget--;

            $this->computedWriter->write($field, $record);

            if (!in_array($field->key, $keys, true)) {
                $keys[] = $field->key;
            }
        }

        return $frame['keys'] === [] ? [] : $keys;
    }

    /**
     * @param  array{record: string, type: string, keys: list<string>, depth: int}  $frame
     */
    private function warnBudgetExhausted(array $frame, int $budgetLimit, bool &$isBudgetWarned): void
    {
        if ($isBudgetWarned) {
            return;
        }

        $isBudgetWarned = true;

        Log::warning('Stopped materialising formula fields because the recompute pass exhausted its budget.', [
            'object_type_id' => $frame['type'],
            'record_id' => $frame['record'],
            'max_computed_fields_per_pass' => $budgetLimit,
        ]);
    }

    /**
     * @param  list<string>  $changedKeys
     * @return Collection<int, FieldDependency>
     */
    private function dependentsOf(string $objectTypeId, array $changedKeys): Collection
    {
        $fieldIds = FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->when($changedKeys !== [], fn ($query) => $query->whereIn('key', $changedKeys))
            ->pluck('id')
            ->all();

        if ($fieldIds === []) {
            return collect();
        }

        return FieldDependency::query()
            ->whereIn('depends_on_field_id', $fieldIds)
            ->get();
    }

    /**
     * @return list<string>
     */
    private function parentsLinking(string $tenantId, string $childRecordId, ?string $relationshipTypeId): array
    {
        $parentIds = RecordLink::query()
            ->where('to_record_id', $childRecordId)
            ->where('tenant_id', $tenantId)
            ->when($relationshipTypeId !== null, fn ($query) => $query->where('relationship_type_id', $relationshipTypeId))
            ->pluck('from_record_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_values($parentIds);
    }
}
