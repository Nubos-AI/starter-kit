<?php

declare(strict_types=1);

namespace App\Activities\Export;

use App\Contracts\Export\FinalizeExportActivityInterface;
use App\Support\Export\ExportFinalizer;

class FinalizeExportActivity implements FinalizeExportActivityInterface
{
    public function __construct(private readonly ExportFinalizer $finalizer) {}

    public function finalizeExport(string $tenantId, string $userId, string $exportJobId): void
    {
        $this->finalizer->finalize($tenantId, $userId, $exportJobId);
    }
}
