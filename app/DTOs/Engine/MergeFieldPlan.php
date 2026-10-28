<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\MergeFieldStrategy;
use App\Enums\Engine\MergeValueOrigin;

readonly class MergeFieldPlan
{
    public function __construct(
        public string $key,
        public string $label,
        public FieldType $fieldType,
        public MergeFieldStrategy $strategy,
        public mixed $targetValue,
        public mixed $sourceValue,
        public mixed $resultValue,
        public MergeValueOrigin $origin,
        public bool $isConflict,
        public bool $requiresDecision,
        public bool $isOverridden,
    ) {}
}
