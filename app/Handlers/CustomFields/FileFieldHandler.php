<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields;

use App\Contracts\CustomFields\RecordBoundFieldHandler;
use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class FileFieldHandler extends AbstractFieldHandler implements RecordBoundFieldHandler
{
    public function fieldType(): FieldType
    {
        return FieldType::File;
    }

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array
    {
        return FilterOperator::forPresence();
    }

    public function cast(mixed $value, FieldDefinition $field): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($this->allowsMultiple($field)) {
            if (!is_array($value)) {
                return null;
            }

            return array_values(array_map(static fn (mixed $id): string => (string) $id, $value));
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function validationRules(FieldDefinition $field): array
    {
        return $this->referenceRules($field, $this->existsInBoundTenant());
    }

    /**
     * @return array<int|string, mixed>
     */
    public function recordValidationRules(FieldDefinition $field, ?CustomRecord $record): array
    {
        $exists = $this->existsInBoundTenant()
            ->where('record_id', $record?->getKey())
            ->where(static function (Builder $query) use ($field): void {
                $query->where('field_key', $field->key)->orWhereNull('field_key');
            });

        return $this->referenceRules($field, $exists);
    }

    protected function searchableValue(mixed $value, FieldDefinition $field): mixed
    {
        return null;
    }

    private function allowsMultiple(FieldDefinition $field): bool
    {
        return ($field->config['multiple'] ?? false) === true;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function referenceRules(FieldDefinition $field, Exists $exists): array
    {
        if ($this->allowsMultiple($field)) {
            return [
                'array',
                '*' => ['string', $exists],
            ];
        }

        return ['string', $exists];
    }

    private function existsInBoundTenant(): Exists
    {
        return Rule::exists('attachments', 'id')->where('tenant_id', (string) TenantContext::currentId());
    }
}
