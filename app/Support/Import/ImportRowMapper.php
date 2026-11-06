<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\DTOs\Import\ImportRowValues;
use App\Enums\CustomFields\FieldType;
use App\Enums\Import\ImportColumnFormat;
use App\Enums\Import\ImportIdentityTarget;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeFieldLookup;
use Illuminate\Database\Eloquent\Collection;

class ImportRowMapper
{
    /** @var non-empty-string */
    private string $relationValueSeparator = ',';

    /**
     * @var array<string, Collection<array-key, FieldDefinition>>
     */
    private array $fieldCache = [];

    public function __construct(
        private readonly ColumnFormatDetector $columnFormatDetector,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $mapping
     */
    public function map(array $row, ObjectType $objectType, array $mapping): ImportRowValues
    {
        $columns = is_array($mapping['columns'] ?? null) ? $mapping['columns'] : [];
        $formats = is_array($mapping['formats'] ?? null) ? $mapping['formats'] : [];
        $fields = $this->fieldsFor($objectType);

        $externalReferenceId = null;
        $recordNumber = null;

        /** @var array<string, mixed> $data */
        $data = [];

        /** @var array<string, array{values: list<string>, field: FieldDefinition}> $relations */
        $relations = [];

        foreach ($columns as $sourceColumn => $target) {
            if (!is_string($target) || $target === '') {
                continue;
            }

            $raw = $this->cellValue($row, (string) $sourceColumn);
            $identity = ImportIdentityTarget::tryFrom($target);

            if ($identity !== null) {
                $normalized = $this->columnFormatDetector->normalize($raw, ImportColumnFormat::Text);
                $value = is_string($normalized) && $normalized !== '' ? $normalized : null;

                match ($identity) {
                    ImportIdentityTarget::ExternalReferenceId => $externalReferenceId = $value,
                    ImportIdentityTarget::RecordNumber => $recordNumber = $value,
                };

                continue;
            }

            $field = $fields->get($target);

            if (!$field instanceof FieldDefinition) {
                continue;
            }

            if ($this->isRelation($field->field_type)) {
                $values = $this->relationValues($raw);

                if ($values !== []) {
                    $relations[$target] = ['values' => $values, 'field' => $field];
                }

                continue;
            }

            $format = $this->resolveFormat($formats, (string) $sourceColumn, $field->field_type);
            $data[$target] = $this->columnFormatDetector->normalize($raw, $format);
        }

        return new ImportRowValues($externalReferenceId, $recordNumber, $data, $relations);
    }

    /**
     * @return list<string>
     */
    private function relationValues(?string $raw): array
    {
        $value = $raw === null ? '' : trim($raw);

        if ($value === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (string $part): string => trim($part),
                explode($this->relationValueSeparator, $value),
            ),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function cellValue(array $row, string $sourceColumn): ?string
    {
        $value = $row[$sourceColumn] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $formats
     */
    private function resolveFormat(array $formats, string $sourceColumn, FieldType $fieldType): ImportColumnFormat
    {
        $declared = $formats[$sourceColumn] ?? null;

        if (is_string($declared)) {
            $format = ImportColumnFormat::tryFrom($declared);

            if ($format !== null) {
                return $format;
            }
        }

        return ImportColumnFormat::forFieldType($fieldType);
    }

    private function isRelation(FieldType $fieldType): bool
    {
        return $fieldType === FieldType::RelationHasMany || $fieldType === FieldType::RelationManyToMany;
    }

    /**
     * @return Collection<array-key, FieldDefinition>
     */
    private function fieldsFor(ObjectType $objectType): Collection
    {
        $key = (string) $objectType->getKey();

        if (isset($this->fieldCache[$key])) {
            return $this->fieldCache[$key];
        }

        /** @var Collection<array-key, FieldDefinition> $fields */
        $fields = $this->fieldLookup->fields($key)->keyBy('key');

        return $this->fieldCache[$key] = $fields;
    }
}
