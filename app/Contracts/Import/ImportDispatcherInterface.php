<?php

declare(strict_types=1);

namespace App\Contracts\Import;

use App\Models\ImportJob;

interface ImportDispatcherInterface
{
    public function start(ImportJob $importJob, ?string $sheet, int $chunkSize): void;
}
