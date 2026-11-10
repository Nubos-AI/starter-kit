<?php

declare(strict_types=1);

namespace App\Handlers\CustomFields;

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\FieldDefinition;
use App\Models\RelationshipType;
use Illuminate\Validation\ValidationException;

class RelationFieldHandler extends AbstractFieldHandler
{
    public function fieldType(): FieldType
    {
        return FieldType::RelationHasMany;
    }

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array
    {
        return [
            FilterOperator::Has,
            FilterOperator::HasNot,
        ];
    }

    public function cast(mixed $value, FieldDefinition $field): mixed
    {
        return null;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function validationRules(FieldDefinition $field): array
    {
        return ['nullable', 'array'];
    }

    /**
     * @throws ValidationException
     */
    public function validateConfig(FieldDefinition $field): RelationshipType
    {
        $config = is_array($field->config) ? $field->config : [];
        $relationshipTypeId = $config['relationship_type_id'] ?? null;

        if (!is_string($relationshipTypeId) || $relationshipTypeId === '') {
            throw ValidationException::withMessages([
                'config' => __('i18n.backend.handlers.custom_fields.relation_field_handler.a_relationship_field_requires_a_relationship_type_to_represent'),
            ]);
        }

        $relationshipType = RelationshipType::query()
            ->whereKey($relationshipTypeId)
            ->where('from_object_type_id', $field->object_type_id)
            ->first();

        if (!$relationshipType instanceof RelationshipType) {
            throw ValidationException::withMessages([
                'config' => __('i18n.backend.handlers.custom_fields.relation_field_handler.the_selected_relationship_type_does_not_originate_from_this'),
            ]);
        }

        return $relationshipType;
    }
}
