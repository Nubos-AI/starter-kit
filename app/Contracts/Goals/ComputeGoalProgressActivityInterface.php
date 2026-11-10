<?php

declare(strict_types=1);

namespace App\Contracts\Goals;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'GoalProgress.')]
interface ComputeGoalProgressActivityInterface
{
    /**
     * @return list<string>
     */
    #[ActivityMethod(name: 'dueGoalIds')]
    public function dueGoalIds(): array;

    #[ActivityMethod(name: 'computeGoalProgress')]
    public function computeGoalProgress(string $goalId): bool;
}
