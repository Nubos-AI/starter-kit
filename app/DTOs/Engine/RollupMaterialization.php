<?php

declare(strict_types=1);

namespace App\DTOs\Engine;

use App\Models\FieldDefinition;

readonly class RollupMaterialization
{
    /**
     * @param  list<FieldDefinition>  $changedFields
     * @param  list<FieldDefinition>  $incompleteFields
     */
    public function __construct(
        public array $changedFields,
        public array $incompleteFields,
    ) {}
}
