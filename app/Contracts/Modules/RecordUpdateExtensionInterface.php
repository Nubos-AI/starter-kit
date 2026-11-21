<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Models\CustomRecord;

interface RecordUpdateExtensionInterface
{
    /** @param array<string, mixed> $input */
    public function updated(CustomRecord $record, array $input): void;
}
