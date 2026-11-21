<?php

declare(strict_types=1);

namespace App\Activities\Import;

use App\Actions\Import\FinalizeImportAction;
use App\Contracts\Import\FinalizeImportActivityInterface;

class FinalizeImportActivity implements FinalizeImportActivityInterface
{
    public function __construct(private readonly FinalizeImportAction $finalizer) {}

    public function finalizeImport(string $tenantId, string $actingUserId, string $importJobId, bool $failed = false): void
    {
        $this->finalizer->execute($tenantId, $actingUserId, $importJobId, $failed);
    }
}
