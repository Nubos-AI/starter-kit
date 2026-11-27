<?php

declare(strict_types=1);

namespace App\Contracts\CustomFields;

use App\Models\CustomRecord;
use App\Models\FieldDefinition;

interface RecordBoundFieldHandler
{
    /**
     * @return array<int|string, mixed>
     */
    public function recordValidationRules(FieldDefinition $field, ?CustomRecord $record): array;
}
