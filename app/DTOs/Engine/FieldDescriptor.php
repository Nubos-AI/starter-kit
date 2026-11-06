<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\CustomFields\FieldType;

readonly class FieldDescriptor
{
    public function __construct(
        public string $key,
        public string $label,
        public FieldType $type,
        public bool $isRequired,
        public bool $isSortable,
        public bool $isFilterable,
        public bool $isTranslatable,
        public ?string $column,
    ) {}
}
