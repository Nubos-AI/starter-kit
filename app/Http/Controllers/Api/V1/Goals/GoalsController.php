<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Goals;

use App\Enums\Api\ApiAccessLevel;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Api\V1\GoalResource;
use App\Http\Resources\Goals\GoalResource as WebGoalResource;
use App\Models\Goal;
use App\Models\Report;
use App\Models\User;
use App\Support\Api\ApiAbilityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalsController extends Controller
{
    public function __construct(private readonly ApiAbilityMap $abilityMap) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Goal::class);

        $user = $this->actingUser($request);

        $goals = $this->tenantGoals($user)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Goal $goal): bool => $user->can('view', $goal)
                && $this->maySeeObjectType($request, $goal))
            ->values();

        return new JsonResponse([
            'data' => GoalResource::collection($goals)->resolve($request),
        ]);
    }

    public function show(Request $request, string $goal): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveGoal($user, $goal);

        $this->authorize('view', $model);

        return new JsonResponse([
            'data' => (new GoalResource($model))->resolve($request),
        ]);
    }

    private function resolveGoal(User $user, string $goal): Goal
    {
        return $this->tenantGoals($user)->whereKey($goal)->firstOrFail();
    }

    /**
     * @return Builder<Goal>
     */
    private function tenantGoals(User $user): Builder
    {
        return Goal::query()
            ->with([
                'report.objectType',
                'periods' => static function (Relation $query): void {
                    $query
                        ->orderByDesc('period_start')
                        ->orderByDesc('created_at')
                        ->limit(WebGoalResource::$periodLimit);
                },
            ])
            ->where('tenant_id', $user->tenant_id);
    }

    private function maySeeObjectType(Request $request, Goal $goal): bool
    {
        $report = $goal->report;

        return $this->abilityMap->satisfiesObjectType(
            $request->user()?->currentAccessToken(),
            $report instanceof Report ? $report->objectType : null,
            ApiAccessLevel::Read,
        );
    }
}
