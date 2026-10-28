<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\FieldDefinition;
use App\Support\Formulas\FormulaFieldTypeMapper;

class StaticFormulaFieldTypeMapper extends FormulaFieldTypeMapper
{
    /**
     * @var array<string, list<FieldDefinition>>
     */
    private array $fieldsByObjectType = [];

    /**
     * @param  list<FieldDefinition>  $fields
     */
    public static function carrying(string $objectTypeId, array $fields): self
    {
        $mapper = new self;
        $mapper->fieldsByObjectType[$objectTypeId] = $fields;

        return $mapper;
    }

    /**
     * @param  list<FieldDefinition>  $fields
     */
    public function withFields(string $objectTypeId, array $fields): self
    {
        $this->fieldsByObjectType[$objectTypeId] = $fields;

        return $this;
    }

    public function resolveField(string $objectTypeId, string $fieldKey): ?FieldDefinition
    {
        foreach ($this->fieldsByObjectType[$objectTypeId] ?? [] as $field) {
            if ($field->key === $fieldKey) {
                return $field;
            }
        }

        return null;
    }
}
