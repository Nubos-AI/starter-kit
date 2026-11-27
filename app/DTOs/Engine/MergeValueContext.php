<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;

readonly class MergeValueContext
{
    public function __construct(
        public FieldDefinition $field,
        public CustomRecord $target,
        public CustomRecord $source,
    ) {}

    public function targetValue(): mixed
    {
        return $this->normalize($this->target->data[$this->field->key] ?? null);
    }

    public function sourceValue(): mixed
    {
        return $this->normalize($this->source->data[$this->field->key] ?? null);
    }

    public function targetIsEmpty(): bool
    {
        return self::isEmpty($this->targetValue());
    }

    public function sourceIsEmpty(): bool
    {
        return self::isEmpty($this->sourceValue());
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function normalize(mixed $value): mixed
    {
        return $value === null && $this->field->field_type === FieldType::MultiSelect ? [] : $value;
    }
}
