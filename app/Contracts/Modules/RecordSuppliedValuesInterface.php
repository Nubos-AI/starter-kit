<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Models\CustomRecord;

interface RecordSuppliedValuesInterface
{
    /** @return array<string, mixed> */
    public function suppliedValues(CustomRecord $record): array;
}
