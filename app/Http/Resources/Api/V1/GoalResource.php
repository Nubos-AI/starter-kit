<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Goal;
use App\Models\GoalPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Goal
 */
class GoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Goal $goal */
        $goal = $this->resource;

        $key = (string) $goal->getKey();

        return [
            'type' => 'goals',
            'id' => $key,
            'attributes' => $this->attributePayload($goal),
            'links' => [
                'self' => url("/api/v1/goals/{$key}"),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributePayload(Goal $goal): array
    {
        return [
            'name' => $goal->name,
            'scopeType' => $goal->scope_type->value,
            'targetUserId' => $goal->target_user_id,
            'targetTeamId' => $goal->target_team_id,
            'includesSubteams' => $goal->includes_subteams,
            'scopeFieldKey' => $goal->scope_field_key,
            'periodFieldKey' => $goal->period_field_key,
            'periodType' => $goal->period_type->value,
            'direction' => $goal->direction->value,
            'targetValue' => $goal->target_value,
            'periods' => $this->whenLoaded(
                'periods',
                fn (): array => array_map($this->periodPayload(...), $goal->periods->all()),
            ),
            'createdAt' => $goal->created_at?->toISOString(),
            'updatedAt' => $goal->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function periodPayload(GoalPeriod $period): array
    {
        return [
            'id' => (string) $period->getKey(),
            'periodStart' => $period->period_start->toISOString(),
            'periodEnd' => $period->period_end->toISOString(),
            'currentValue' => $period->current_value,
            'calculatedAt' => $period->calculated_at?->toISOString(),
        ];
    }
}
