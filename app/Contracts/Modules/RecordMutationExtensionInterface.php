<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Models\CustomRecord;

interface RecordMutationExtensionInterface
{
    public function saved(CustomRecord $record): void;

    public function deleting(CustomRecord $record): void;
}
