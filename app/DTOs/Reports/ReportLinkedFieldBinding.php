<?php

declare(strict_types=1);

namespace App\DTOs\Reports;

use App\Models\FieldDefinition;

readonly class ReportLinkedFieldBinding
{
    public function __construct(
        public string $qualifiedKey,
        public FieldDefinition $relationField,
        public string $relationshipTypeId,
        public string $linkedObjectTypeId,
        public FieldDefinition $linkedField,
    ) {}
}
