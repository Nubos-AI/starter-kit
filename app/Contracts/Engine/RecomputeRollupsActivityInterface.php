<?php

declare(strict_types=1);

namespace App\Contracts\Engine;

use App\DTOs\Engine\RecordChangeBatch;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Rollups.')]
interface RecomputeRollupsActivityInterface
{
    #[ActivityMethod(name: 'recomputeRollups')]
    public function recomputeRollups(RecordChangeBatch $changes): int;
}
