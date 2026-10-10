<?php

declare(strict_types=1);

namespace App\Http\Controllers\Goals;

use App\Actions\Goals\BulkDeleteGoalsAction;
use App\Actions\Goals\CreateGoalAction;
use App\Actions\Goals\DeleteGoalAction;
use App\Actions\Goals\UpdateGoalAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Goals\GoalResource;
use App\Models\Goal;
use App\Models\User;
use App\Support\Goals\GoalEditorPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoalsController extends Controller
{
    public function __construct(
        private readonly CreateGoalAction $createGoal,
        private readonly UpdateGoalAction $updateGoal,
        private readonly DeleteGoalAction $deleteGoal,
        private readonly BulkDeleteGoalsAction $bulkDeleteGoals,
        private readonly GoalEditorPresenter $editorPresenter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Goal::class);

        $user = $this->actingUser($request);

        $goals = $this->tenantGoals($user)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Goal $goal): bool => $user->can('view', $goal))
            ->values();

        return Inertia::render('goals/Index', [
            'goals' => GoalResource::collection($goals)->resolve($request),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Goal::class);

        return Inertia::render('goals/Form', array_merge([
            'mode' => 'create',
            'goal' => null,
        ], $this->editorPresenter->payload($this->actingUser($request))));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Goal::class);

        $this->createGoal->execute($this->actingUser($request), $request->all());

        return to_route('goals.index');
    }

    public function edit(Request $request, string $goal): Response
    {
        $user = $this->actingUser($request);
        $model = $this->resolveGoal($user, $goal);

        $this->authorize('update', $model);

        return Inertia::render('goals/Form', array_merge([
            'mode' => 'edit',
            'goal' => (new GoalResource($model))->resolve($request),
        ], $this->editorPresenter->payload($user)));
    }

    public function update(Request $request, string $goal): RedirectResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveGoal($user, $goal);

        $this->authorize('update', $model);

        $this->updateGoal->execute($user, $model, $request->all());

        return to_route('goals.index');
    }

    public function destroy(Request $request, string $goal): RedirectResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveGoal($user, $goal);

        $this->authorize('delete', $model);

        $this->deleteGoal->execute($user, $model);

        return to_route('goals.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Goal::class);

        $this->bulkDeleteGoals->execute($this->actingUser($request), $request->all());

        return to_route('goals.index');
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
                'report',
                'targetUser',
                'targetTeam',
                'periods' => static function (Relation $query): void {
                    $query
                        ->orderByDesc('period_start')
                        ->orderByDesc('created_at')
                        ->limit(GoalResource::$periodLimit);
                },
            ])
            ->where('tenant_id', $user->tenant_id);
    }
}
