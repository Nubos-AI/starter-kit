<?php

declare(strict_types=1);

namespace App\Http\Resources\Goals;

use App\Enums\Goals\GoalActionRefusalReason;
use App\Enums\Goals\GoalScopeType;
use App\Models\Goal;
use App\Models\Report;
use App\Models\Team;
use App\Models\User;
use App\Support\Users\UserOptionPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Goal
 */
class GoalResource extends JsonResource
{
    public static int $periodLimit = 13;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Goal $goal */
        $goal = $this->resource;

        $user = $request->user();

        $canView = $user instanceof User && $user->can('view', $goal);
        $canUpdate = $canView && $user->can('update', $goal);
        $canDelete = $canView && $user->can('delete', $goal);

        return [
            'id' => (string) $goal->getKey(),
            'name' => $goal->name,
            'report_id' => $goal->report_id,
            'report' => $this->whenLoaded('report', fn (): ?array => $this->reportPayload($goal)),
            'scope_type' => $goal->scope_type->value,
            'target_user_id' => $goal->target_user_id,
            'target_team_id' => $goal->target_team_id,
            'target' => $this->targetPayload($goal),
            'includes_subteams' => $goal->includes_subteams,
            'scope_field_key' => $goal->scope_field_key,
            'period_field_key' => $goal->period_field_key,
            'period_type' => $goal->period_type->value,
            'direction' => $goal->direction->value,
            'target_value' => $goal->target_value,
            'periods' => $this->whenLoaded(
                'periods',
                fn (): array => GoalPeriodResource::collection($goal->periods)->resolve($request),
            ),
            'can_update' => $canUpdate,
            'can_delete' => $canDelete,
            'update_reason' => $this->refusalReason($canUpdate, $canView)?->value,
            'delete_reason' => $this->refusalReason($canDelete, $canView)?->value,
            'updated_at' => $goal->updated_at?->toIso8601String(),
        ];
    }

    private function refusalReason(bool $isAllowed, bool $canView): ?GoalActionRefusalReason
    {
        if ($isAllowed) {
            return null;
        }

        return $canView
            ? GoalActionRefusalReason::NotOwner
            : GoalActionRefusalReason::NotVisible;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function targetPayload(Goal $goal): ?array
    {
        if ($goal->scope_type === GoalScopeType::User) {
            $targetUser = $goal->targetUser;

            return $targetUser instanceof User
                ? (new UserOptionPresenter)->present($targetUser)
                : null;
        }

        if ($goal->scope_type === GoalScopeType::Team) {
            $targetTeam = $goal->targetTeam;

            return $targetTeam instanceof Team
                ? ['value' => (string) $targetTeam->getKey(), 'label' => $targetTeam->name]
                : null;
        }

        return null;
    }

    /**
     * @return array{id: string, name: string, object_type_id: string}|null
     */
    private function reportPayload(Goal $goal): ?array
    {
        $report = $goal->report;

        if (!$report instanceof Report) {
            return null;
        }

        return [
            'id' => (string) $report->getKey(),
            'name' => $report->name,
            'object_type_id' => $report->object_type_id,
        ];
    }
}
