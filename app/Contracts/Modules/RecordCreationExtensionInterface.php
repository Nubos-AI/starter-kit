<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Models\CustomRecord;
use App\Models\ObjectType;

interface RecordCreationExtensionInterface
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function attributes(ObjectType $objectType, array $input): array;

    /** @return array<string, mixed> */
    public function duplicateAttributes(CustomRecord $record): array;

    /** @return array<string, mixed> */
    public function auditAttributes(CustomRecord $record): array;
}
