<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecordSearchText
{
    /**
     * @var list<literal-string>
     */
    private array $businessKeyColumns = ['record_number', 'external_reference_id'];

    public function __construct(
        private readonly IndexRegistry $indexRegistry,
        private readonly ObjectTypeRegistry $objectTypes,
    ) {}

    public function isSearchable(FieldDefinition $field): bool
    {
        return $field->is_searchable
            && !$field->is_translatable
            && !$field->is_encrypted
            && $field->field_type->isFreeTextSearchable();
    }

    /**
     * @param  Collection<array-key, FieldDefinition>  $fields
     * @return Collection<int, FieldDefinition>
     */
    public function searchableFields(Collection $fields): Collection
    {
        return $fields
            ->filter(fn (FieldDefinition $field): bool => $this->isSearchable($field))
            ->values();
    }

    /**
     * @return list<literal-string>
     */
    public function businessKeyColumns(): array
    {
        return $this->businessKeyColumns;
    }

    /**
     * @return literal-string
     */
    public function fieldText(FieldDefinition $field): string
    {
        return "(data->>'".$this->indexRegistry->safeKey($field->key)."')";
    }

    /**
     * @param  Collection<array-key, FieldDefinition>  $fields
     * @return literal-string
     */
    public function expression(Collection $fields): string
    {
        /** @var list<literal-string> $parts */
        $parts = [];

        foreach ($this->searchableFields($fields) as $field) {
            $parts[] = $this->fieldText($field);
        }

        foreach ($this->businessKeyColumns as $column) {
            $parts[] = $column;
        }

        return 'concat_ws(chr(31), '.implode(', ', $parts).')';
    }

    /**
     * @param  Collection<array-key, FieldDefinition>|null  $fields
     */
    public function refresh(CustomRecord $record, ?Collection $fields = null): void
    {
        $ownFields = $fields === null
            ? $this->recordFields($record)
            : $fields->filter(static fn (FieldDefinition $field): bool => $field->object_type_id === $record->object_type_id);

        $this->write($record->tenant_id, $record->object_type_id, [(string) $record->getKey()], $ownFields);
    }

    /**
     * @param  list<string>  $recordIds
     */
    public function refreshMany(string $tenantId, string $objectTypeId, array $recordIds): int
    {
        if ($recordIds === []) {
            return 0;
        }

        return $this->write($tenantId, $objectTypeId, $recordIds, $this->fieldsOf($tenantId, $objectTypeId));
    }

    /**
     * @param  list<string>  $recordIds
     * @param  Collection<array-key, FieldDefinition>  $fields
     */
    private function write(string $tenantId, string $objectTypeId, array $recordIds, Collection $fields): int
    {
        $expression = $this->expression($fields);

        return CustomRecord::query()
            ->withoutGlobalScopes()
            ->toBase()
            ->where('tenant_id', $tenantId)
            ->where('object_type_id', $objectTypeId)
            ->whereIn('id', $recordIds)
            ->update(['search_text' => DB::raw($expression)]);
    }

    /**
     * @return Collection<array-key, FieldDefinition>
     */
    private function recordFields(CustomRecord $record): Collection
    {
        return TenantContext::currentId() === $record->tenant_id
            ? $this->objectTypes->fields($record->object_type_id)
            : $this->fieldsOf($record->tenant_id, $record->object_type_id);
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    private function fieldsOf(string $tenantId, string $objectTypeId): Collection
    {
        return FieldDefinition::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('object_type_id', $objectTypeId)
            ->orderBy('list_position')
            ->orderBy('created_at')
            ->get(['id', 'key', 'field_type', 'is_searchable', 'is_translatable', 'is_encrypted'])
            ->toBase();
    }
}
