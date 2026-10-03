<?php

declare(strict_types=1);

namespace App\Activities\Import;

use App\Contracts\Import\FinalizeImportActivityInterface;
use App\Support\Import\ImportFinalizer;

class FinalizeImportActivity implements FinalizeImportActivityInterface
{
    public function __construct(private readonly ImportFinalizer $finalizer) {}

    public function finalizeImport(string $tenantId, string $actingUserId, string $importJobId): void
    {
        $this->finalizer->finalize($tenantId, $actingUserId, $importJobId);
    }
}
