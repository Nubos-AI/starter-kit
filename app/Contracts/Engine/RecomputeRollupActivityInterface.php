<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Rollups.')]
interface RecomputeRollupActivityInterface
{
    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    #[ActivityMethod(name: 'recomputeRollup')]
    public function recomputeRollup(string $tenantId, string $objectTypeId, string $recordId, array $changedFieldKeys): void;

    #[ActivityMethod(name: 'recomputeOwnRollups')]
    public function recomputeOwnRollups(string $tenantId, string $objectTypeId, string $recordId): void;
}
