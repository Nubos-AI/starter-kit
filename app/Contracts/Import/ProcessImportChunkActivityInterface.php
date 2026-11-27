<?php

declare(strict_types=1);

namespace App\Contracts\Import;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Import.')]
interface ProcessImportChunkActivityInterface
{
    #[ActivityMethod(name: 'processImportChunk')]
    public function processImportChunk(
        string $tenantId,
        string $actingUserId,
        string $importJobId,
        string $disk,
        string $path,
        string $format,
        ?string $sheet,
        int $offset,
        int $limit,
    ): void;
}
