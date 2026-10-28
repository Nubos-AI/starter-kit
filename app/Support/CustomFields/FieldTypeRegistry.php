<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Casts\EncryptedJsonValue;
use App\Contracts\CustomFields\FieldHandler;
use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Exceptions\CustomFields\UnknownFieldTypeException;
use App\Models\FieldDefinition;
use App\Support\Abstracts\ConfigDrivenRegistry;
use App\Support\I18n\TranslatableValueResolver;
use Illuminate\Contracts\Container\Container;

/**
 * @extends ConfigDrivenRegistry<FieldHandler>
 */
class FieldTypeRegistry extends ConfigDrivenRegistry
{
    public function __construct(
        Container $container,
        private readonly TranslatableValueResolver $resolver,
        private readonly EncryptedJsonValue $encrypted,
    ) {
        parent::__construct($container);
    }

    public function hasHandler(FieldType $type): bool
    {
        return $this->entryClass($type->value) !== null;
    }

    /**
     * @throws UnknownFieldTypeException
     */
    public function handlerFor(FieldType $type): FieldHandler
    {
        return $this->resolve($this->entryClass($type->value) ?? throw new UnknownFieldTypeException($type));
    }

    /**
     * @return list<FilterOperator>
     */
    public function filterOperators(FieldDefinition $field): array
    {
        if ($field->is_encrypted || !$field->is_filterable) {
            return [];
        }

        if (!$this->hasHandler($field->field_type)) {
            return [];
        }

        return $this->handlerFor($field->field_type)->filterOperators($field);
    }

    public function read(mixed $raw, FieldDefinition $field): mixed
    {
        $value = $raw;

        if ($field->is_encrypted) {
            $value = $this->encrypted->get($field, $field->key, $value, []);
        }

        if ($field->is_translatable) {
            $value = $this->resolver->resolve($value);
        }

        return $this->handlerFor($field->field_type)->cast($value, $field);
    }

    public function write(mixed $input, FieldDefinition $field): mixed
    {
        $handler = $this->handlerFor($field->field_type);

        if ($field->is_translatable && is_array($input)) {
            $value = [];

            foreach ($input as $locale => $localeValue) {
                $value = $this->resolver->put($value, (string) $locale, $handler->cast($localeValue, $field));
            }
        } else {
            $value = $handler->cast($input, $field);
        }

        if ($field->is_encrypted) {
            $value = $this->encrypted->set($field, $field->key, $value, []);
        }

        return $value;
    }

    public function toSearchable(mixed $raw, FieldDefinition $field): mixed
    {
        if ($field->is_encrypted) {
            return null;
        }

        $value = $field->is_translatable ? $this->resolver->resolve($raw) : $raw;
        $handler = $this->handlerFor($field->field_type);

        return $handler->toSearchable($handler->cast($value, $field), $field);
    }

    protected function configKey(): string
    {
        return 'engine.field_type_handlers';
    }

    /**
     * @return class-string<FieldHandler>
     */
    protected function contract(): string
    {
        return FieldHandler::class;
    }
}
