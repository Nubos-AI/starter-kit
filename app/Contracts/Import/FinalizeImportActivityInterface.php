<?php

declare(strict_types=1);

namespace App\Contracts\Import;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Import.')]
interface FinalizeImportActivityInterface
{
    #[ActivityMethod(name: 'finalizeImport')]
    public function finalizeImport(string $tenantId, string $actingUserId, string $importJobId): void;
}
