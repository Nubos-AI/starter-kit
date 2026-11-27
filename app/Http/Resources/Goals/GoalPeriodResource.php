<?php

declare(strict_types=1);

namespace App\Http\Resources\Goals;

use App\Models\GoalPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GoalPeriod
 */
class GoalPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var GoalPeriod $period */
        $period = $this->resource;

        return [
            'id' => (string) $period->getKey(),
            'goal_id' => $period->goal_id,
            'period_start' => $period->period_start->toIso8601String(),
            'period_end' => $period->period_end->toIso8601String(),
            'current_value' => $period->current_value,
            'calculated_at' => $period->calculated_at?->toIso8601String(),
        ];
    }
}
