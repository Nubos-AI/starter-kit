<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\nubos\example\src;

use App\Contracts\Modules\RecordRelocatedFieldsInterface;
use App\Models\ObjectType;

class ExampleRecordRelocatedFields implements RecordRelocatedFieldsInterface
{
    /** @return list<string> */
    public function relocatedFieldKeys(ObjectType $objectType): array
    {
        return ['cost', 'margin'];
    }
}
