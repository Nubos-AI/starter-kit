<?php

declare(strict_types=1);

namespace App\DTOs\Import;

use App\Models\CustomRecord;
use App\Models\FieldDefinition;

readonly class ImportRecordMatch
{
    public function __construct(
        public CustomRecord $record,
        public bool $viaIdentityColumn,
        public ?FieldDefinition $uniqueField = null,
        public bool $isVisible = true,
    ) {}
}
