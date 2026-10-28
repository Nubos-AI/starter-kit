<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields;

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\FieldDefinition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class FileFieldHandler extends AbstractFieldHandler
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
        if ($this->allowsMultiple($field)) {
            return [
                'array',
                '*' => ['string', $this->existsInBoundTenant()],
            ];
        }

        return ['string', $this->existsInBoundTenant()];
    }

    protected function searchableValue(mixed $value, FieldDefinition $field): mixed
    {
        return null;
    }

    private function allowsMultiple(FieldDefinition $field): bool
    {
        return ($field->config['multiple'] ?? false) === true;
    }

    private function existsInBoundTenant(): Exists
    {
        return Rule::exists('attachments', 'id')->where('tenant_id', (string) TenantContext::currentId());
    }
}
