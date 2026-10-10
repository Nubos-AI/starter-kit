<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Contracts\CustomFields\FieldHandler;
use App\Enums\CustomFields\FieldType;
use App\Exceptions\StaleRecordException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectTypeDefinitionVersion;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\SystemObjectTypeGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChangeFieldTypeAction
{
    public function __construct(
        private readonly FieldTypeRegistry $registry,
        private readonly AuditRecorder $auditRecorder,
        private readonly SnapshotDefinitionAction $snapshotDefinition,
        private readonly SystemObjectTypeGuard $systemObjectTypeGuard,
    ) {}

    /**
     * @return array{version: ObjectTypeDefinitionVersion, converted: int, not_convertible: int, examples: list<string>}
     *
     * @throws Throwable
     */
    public function execute(FieldDefinition $field, FieldType $newType, bool $confirmLossy = false): array
    {
        $this->systemObjectTypeGuard->assertFieldIsManageable($field);
        $this->guardConvertible($field->field_type, $newType);

        $castingField = $this->castingClone($field, $newType);
        $handler = $this->registry->handlerFor($newType);

        $preview = $this->classify($field, $castingField, $handler);

        if ($preview['not_convertible'] > 0 && !$confirmLossy) {
            throw ValidationException::withMessages([
                'field_type' => sprintf(
                    __('i18n.backend.actions.engine.change_field_type_action.lossy_type_change_d_of_d_values_cannot_be'),
                    $preview['not_convertible'],
                    $preview['total'],
                    $preview['examples'] === [] ? '' : ' (z. B. '.implode(', ', $preview['examples']).')',
                ),
            ]);
        }

        $oldType = $field->field_type;
        $objectType = $field->objectType()->firstOrFail();

        $this->snapshotDefinition->execute(
            $objectType,
            sprintf(__('i18n.backend.actions.engine.change_field_type_action.before_field_type_change_s_s'), $field->key, $oldType->value),
        );

        $converted = $this->convertAllRecords($field, $castingField, $handler);

        $field->field_type = $newType;
        $field->save();

        $version = $this->snapshotDefinition->execute(
            $objectType,
            sprintf(__('i18n.backend.actions.engine.change_field_type_action.field_type_change_s_s_s'), $field->key, $oldType->value, $newType->value),
        );

        return [
            'version' => $version,
            'converted' => $converted,
            'not_convertible' => $preview['not_convertible'],
            'examples' => $preview['examples'],
        ];
    }

    private function guardConvertible(FieldType $from, FieldType $to): void
    {
        if (!$from->isTypeChangeable() || !$to->isTypeChangeable()) {
            throw ValidationException::withMessages([
                'field_type' => __('i18n.backend.actions.engine.change_field_type_action.changing_to_or_from_relationship_file_geo_rollup_and'),
            ]);
        }
    }

    /**
     * @return array{total: int, not_convertible: int, examples: list<string>}
     */
    private function classify(FieldDefinition $field, FieldDefinition $castingField, FieldHandler $handler): array
    {
        $total = 0;
        $notConvertible = 0;
        $examples = [];
        $exampleLimit = (int) config('engine.field_type_change.example_limit');

        CustomRecord::query()
            ->withoutGlobalScopes()
            ->ofType($field->object_type_id)
            ->whereNotNull('data')
            ->orderBy('id')
            ->chunkById(500, function (Collection $records) use ($field, $castingField, $handler, $exampleLimit, &$total, &$notConvertible, &$examples): void {
                foreach ($records as $record) {
                    $data = $record->data ?? [];

                    if (!array_key_exists($field->key, $data) || $data[$field->key] === null || $data[$field->key] === '') {
                        continue;
                    }

                    $total++;

                    if ($handler->cast($data[$field->key], $castingField) === null) {
                        $notConvertible++;

                        if (count($examples) < $exampleLimit) {
                            $examples[] = (string) $data[$field->key];
                        }
                    }
                }
            });

        return [
            'total' => $total,
            'not_convertible' => $notConvertible,
            'examples' => $examples,
        ];
    }

    private function convertAllRecords(FieldDefinition $field, FieldDefinition $castingField, FieldHandler $handler): int
    {
        $converted = 0;

        CustomRecord::query()
            ->withoutGlobalScopes()
            ->ofType($field->object_type_id)
            ->whereNotNull('data')
            ->orderBy('id')
            ->chunkById(500, function (Collection $records) use ($field, $castingField, $handler, &$converted): void {
                foreach ($records as $record) {
                    if ($this->convertRecord((string) $record->getKey(), $field, $castingField, $handler)) {
                        $converted++;
                    }
                }
            });

        return $converted;
    }

    /**
     * @throws Throwable
     */
    private function convertRecord(string $recordId, FieldDefinition $field, FieldDefinition $castingField, FieldHandler $handler): bool
    {
        return DB::transaction(function () use ($recordId, $field, $castingField, $handler): bool {
            $record = CustomRecord::query()
                ->withoutGlobalScopes()
                ->whereKey($recordId)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return false;
            }

            $data = $record->data ?? [];

            if (!array_key_exists($field->key, $data) || $data[$field->key] === null) {
                return false;
            }

            $original = $data[$field->key];
            $newValue = $handler->cast($original, $castingField);
            $data[$field->key] = $newValue;
            $nextVersion = $record->version + 1;

            $affected = CustomRecord::query()
                ->withoutGlobalScopes()
                ->where('id', $record->getKey())
                ->where('version', $record->version)
                ->update([
                    'data' => json_encode($data),
                    'version' => $nextVersion,
                ]);

            if ($affected === 0) {
                throw new StaleRecordException;
            }

            $this->auditRecorder->record(
                $record,
                [$field->key => $original],
                [$field->key => $newValue],
                $nextVersion,
            );

            return true;
        });
    }

    private function castingClone(FieldDefinition $field, FieldType $newType): FieldDefinition
    {
        $clone = $field->replicate();
        $clone->field_type = $newType;

        return $clone;
    }
}
