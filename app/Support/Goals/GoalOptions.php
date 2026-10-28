<?php

declare(strict_types=1);

namespace App\Support\Goals;

use App\Models\Goal;
use App\Models\Report;
use App\Models\User;

class GoalOptions
{
    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public function forUser(User $user): array
    {
        $goals = Goal::query()
            ->where('tenant_id', $user->tenant_id)
            ->with('report')
            ->orderBy('name')
            ->get();

        $options = [];

        foreach ($goals as $goal) {
            if ($user->cannot('view', $goal)) {
                continue;
            }

            $report = $goal->report;

            $options[] = [
                'value' => (string) $goal->getKey(),
                'label' => $goal->name,
                'description' => $report instanceof Report ? $report->name : '',
            ];
        }

        return $options;
    }
}
