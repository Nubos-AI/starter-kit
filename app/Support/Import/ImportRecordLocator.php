<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\DTOs\Import\ImportRecordMatch;
use App\Enums\Import\ImportIdentityTarget;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Engine\ObjectTypeFieldLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ImportRecordLocator
{
    /**
     * @var array<string, Collection<array-key, FieldDefinition>>
     */
    private array $fieldCache = [];

    public function __construct(
        private readonly FieldTypeRegistry $registry,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function locate(
        ObjectType $objectType,
        ?string $externalReferenceId,
        ?string $recordNumber,
        array $data,
        string $tenantId,
    ): ?ImportRecordMatch {
        $byIdentity = $this->byIdentity($objectType, $externalReferenceId, $recordNumber, $tenantId);

        if ($byIdentity instanceof CustomRecord) {
            return $this->matchOf($byIdentity, true);
        }

        $casted = $this->castForLookup($objectType, $data);

        $duplicate = $objectType->findDuplicates($casted, $tenantId)->first();

        if ($duplicate instanceof CustomRecord) {
            return $this->matchOf($duplicate, false);
        }

        foreach ($this->uniqueFields($objectType) as $field) {
            $value = $casted[$field->key] ?? null;

            if ($value === null) {
                continue;
            }

            $match = $this->baseQuery($objectType, $tenantId)
                ->whereField($field->key, $value)
                ->first();

            if ($match instanceof CustomRecord) {
                return $this->matchOf($match, false, $field);
            }
        }

        return null;
    }

    public function detectsDuplicates(ObjectType $objectType): bool
    {
        return ($objectType->dedup_keys ?? []) !== [] || $this->uniqueFields($objectType)->isNotEmpty();
    }

    /**
     * @return Collection<array-key, FieldDefinition>
     */
    public function uniqueFields(ObjectType $objectType): Collection
    {
        /** @var Collection<array-key, FieldDefinition> $unique */
        $unique = $this->fieldsFor($objectType)->filter(
            static fn (FieldDefinition $field): bool => $field->is_unique
                && !$field->is_encrypted
                && !$field->is_translatable,
        );

        return $unique;
    }

    private function matchOf(CustomRecord $record, bool $viaIdentityColumn, ?FieldDefinition $uniqueField = null): ImportRecordMatch
    {
        $isVisible = CustomRecord::query()
            ->where('tenant_id', $record->tenant_id)
            ->whereKey($record->getKey())
            ->exists();

        return new ImportRecordMatch($record, $viaIdentityColumn, $uniqueField, $isVisible);
    }

    private function byIdentity(
        ObjectType $objectType,
        ?string $externalReferenceId,
        ?string $recordNumber,
        string $tenantId,
    ): ?CustomRecord {
        if ($externalReferenceId !== null && $externalReferenceId !== '') {
            return $this->baseQuery($objectType, $tenantId)
                ->where(ImportIdentityTarget::ExternalReferenceId->column(), $externalReferenceId)
                ->first();
        }

        if ($recordNumber !== null && $recordNumber !== '') {
            return $this->baseQuery($objectType, $tenantId)
                ->where(ImportIdentityTarget::RecordNumber->column(), $recordNumber)
                ->first();
        }

        return null;
    }

    /**
     * @return Builder<CustomRecord>
     */
    private function baseQuery(ObjectType $objectType, string $tenantId): Builder
    {
        return CustomRecord::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->ofType($objectType)
            ->whereNull('deleted_at');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function castForLookup(ObjectType $objectType, array $data): array
    {
        $fields = $this->fieldsFor($objectType);
        $casted = [];

        foreach ($data as $key => $value) {
            $field = $fields->get($key);

            $casted[$key] = $field instanceof FieldDefinition
                ? $this->registry->handlerFor($field->field_type)->cast($value, $field)
                : $value;
        }

        return $casted;
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
