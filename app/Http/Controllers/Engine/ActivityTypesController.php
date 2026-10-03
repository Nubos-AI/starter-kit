<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\BulkDeleteActivityTypesAction;
use App\Actions\Engine\CreateActivityTypeAction;
use App\Actions\Engine\DeleteActivityTypeAction;
use App\Actions\Engine\UpdateActivityTypeAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\ActivityType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityTypesController extends Controller
{
    public function __construct(
        private readonly CreateActivityTypeAction $createActivityType,
        private readonly UpdateActivityTypeAction $updateActivityType,
        private readonly DeleteActivityTypeAction $deleteActivityType,
        private readonly BulkDeleteActivityTypesAction $bulkDeleteActivityTypes,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->actingUser($request);

        $activityTypes = ActivityType::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ActivityType $type): array => $this->indexPayload($type, $user))
            ->all();

        return Inertia::render('activityTypes/Index', [
            'activityTypes' => $activityTypes,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('activityTypes/Form', [
            'mode' => 'create',
            'activityType' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->createActivityType->execute($request->all());

        return to_route('engine.activity-types.index');
    }

    public function edit(ActivityType $activityType): Response
    {
        return Inertia::render('activityTypes/Form', [
            'mode' => 'edit',
            'activityType' => $this->payload($activityType),
        ]);
    }

    public function update(Request $request, ActivityType $activityType): RedirectResponse
    {
        $this->updateActivityType->execute($activityType, $request->all());

        return to_route('engine.activity-types.index');
    }

    public function destroy(ActivityType $activityType): RedirectResponse
    {
        $this->deleteActivityType->execute($activityType);

        return to_route('engine.activity-types.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->bulkDeleteActivityTypes->execute($this->actingUser($request), $request->all());

        return to_route('engine.activity-types.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function indexPayload(ActivityType $type, User $user): array
    {
        return [
            ...$this->payload($type),
            'can_update' => $user->hasPermission('activity-types.update'),
            'can_delete' => $user->hasPermission('activity-types.delete'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function payload(ActivityType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
        ];
    }
}
