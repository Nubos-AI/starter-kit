<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\nubos\example\src;

use App\Contracts\Modules\RecordSuppliedValuesInterface;
use App\Models\CustomRecord;

class ExampleRecordSuppliedValues implements RecordSuppliedValuesInterface
{
    /** @return array<string, mixed> */
    public function suppliedValues(CustomRecord $record): array
    {
        return ['price' => '49.00'];
    }
}
