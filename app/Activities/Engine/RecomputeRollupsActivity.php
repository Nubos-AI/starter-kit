<?php

declare(strict_types=1);

namespace App\Activities\Engine;

use App\Contracts\Engine\RecomputeRollupsActivityInterface;
use App\DTOs\Engine\RecordChangeBatch;
use App\Support\Engine\RollupDebounceStarter;

class RecomputeRollupsActivity implements RecomputeRollupsActivityInterface
{
    public function __construct(private readonly RollupDebounceStarter $starter) {}

    public function recomputeRollups(RecordChangeBatch $changes): int
    {
        foreach ($changes->changes as $change) {
            $this->starter->start(
                $change->tenantId,
                $change->objectTypeId,
                $change->recordId,
                $change->changedFieldKeys,
            );
        }

        return $changes->count();
    }
}
