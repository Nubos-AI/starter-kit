<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'fields.')]
interface FieldMaintenanceActivityInterface
{
    #[ActivityMethod(name: 'syncFieldIndexes')]
    public function syncFieldIndexes(string $tenantId, string $fieldDefinitionId): bool;

    #[ActivityMethod(name: 'requestRollupBackfill')]
    public function requestRollupBackfill(string $tenantId, string $fieldDefinitionId): int;
}
