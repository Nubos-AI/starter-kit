<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType as ObjectTypeModel;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\NullType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;

class ObjectTypeSchemaBuilder
{
    public function component(ObjectTypeModel $type): ObjectType
    {
        $schema = new ObjectType;

        foreach ($this->spine() as $key => $spineType) {
            $schema->addProperty($key, $spineType);
        }

        foreach ($type->fieldDefinitions as $field) {
            $schema->addProperty($field->key, $this->property($field));
        }

        return $schema->setRequired(array_keys($this->spine()));
    }

    /**
     * @return array<string, Type>
     */
    private function spine(): array
    {
        return [
            'recordNumber' => (new StringType)->nullable(true),
            'externalReferenceId' => (new StringType)->nullable(true),
            'version' => new IntegerType,
            'data' => (new ObjectType)->additionalProperties(new MixedType),
            'createdAt' => (new StringType)->format('date-time')->nullable(true),
            'updatedAt' => (new StringType)->format('date-time')->nullable(true),
        ];
    }

    private function property(FieldDefinition $field): Type
    {
        $type = $this->baseType($field);

        if (!$field->is_required) {
            $type = $this->nullableType($type);
        }

        return $field->field_type->isDerived() ? $this->readOnly($type) : $type;
    }

    private function nullableType(Type $type): Type
    {
        if ($type instanceof AnyOf) {
            return $type->setItems([...$type->items, new NullType]);
        }

        return $type->nullable(true);
    }

    private function baseType(FieldDefinition $field): Type
    {
        return match ($field->field_type) {
            FieldType::TextShort, FieldType::TextLong, FieldType::Phone => new StringType,
            FieldType::Email => (new StringType)->format('email'),
            FieldType::Url => (new StringType)->format('uri'),
            FieldType::Number => new IntegerType,
            FieldType::Decimal, FieldType::Money => $this->numericOrStringType(),
            FieldType::Date => (new StringType)->format('date'),
            FieldType::DateTime => (new StringType)->format('date-time'),
            FieldType::Boolean => new BooleanType,
            FieldType::SingleSelect => $this->selectType($field),
            FieldType::MultiSelect => (new ArrayType)->setItems($this->selectType($field)),
            FieldType::RelationHasMany, FieldType::RelationManyToMany => (new ArrayType)->setItems(new StringType),
            FieldType::File => $this->fileType($field),
            FieldType::GeoAddress => (new ObjectType)->additionalProperties(new MixedType),
            FieldType::Rollup => new NumberType,
            FieldType::Computed => new MixedType,
        };
    }

    private function numericOrStringType(): Type
    {
        return (new AnyOf)->setItems([new NumberType, new StringType]);
    }

    private function fileType(FieldDefinition $field): Type
    {
        return ($field->config['multiple'] ?? false) === true
            ? (new ArrayType)->setItems(new StringType)
            : new StringType;
    }

    private function selectType(FieldDefinition $field): StringType
    {
        $type = new StringType;
        $options = $this->options($field);

        return $options === [] ? $type : $type->enum($options);
    }

    /**
     * @return list<string>
     */
    private function options(FieldDefinition $field): array
    {
        $options = $field->config['options'] ?? null;

        if (!is_array($options)) {
            return [];
        }

        return array_values(array_filter($options, 'is_string'));
    }

    private function readOnly(Type $inner): Type
    {
        return new class($inner) extends Type
        {
            public function __construct(private Type $inner)
            {
                parent::__construct($inner->type);
            }

            public function serializeAs31(): mixed
            {
                return [...(array) $this->inner->serializeAs31(), 'readOnly' => true];
            }

            public function serializeAs32(): mixed
            {
                return [...(array) $this->inner->serializeAs32(), 'readOnly' => true];
            }
        };
    }
}
