<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Activities\DeleteRecordActivityAction;
use App\Actions\Activities\SaveRecordActivityAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\RecordActivityResource;
use App\Models\ActivityType;
use App\Models\CustomRecord;
use App\Models\RecordActivity;
use App\Models\User;
use App\Support\Links\SourceLinkCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecordActivitiesController extends Controller
{
    public function index(Request $request, CustomRecord $record, SourceLinkCatalog $sourceLinkCatalog): AnonymousResourceCollection
    {
        $this->authorize('view', $record);

        $page = RecordActivity::query()->where('record_id', $record->id)
            ->with(['activityType', 'assignee'])->orderByDesc('occurred_at')->orderByDesc('id')->paginate(25);
        $links = $sourceLinkCatalog->linksFor($this->actingUser($request), $page->getCollection());

        return RecordActivityResource::collection(
            $page->through(fn (RecordActivity $activity): RecordActivityResource => RecordActivityResource::withLinksFrom($activity, $links))
        )->additional([
            'activityTypes' => ActivityType::query()->orderBy('name')->get(['id', 'name']),
            'assignees' => User::query()->where('tenant_id', $record->tenant_id)->where('is_service', false)->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name'])->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
            'canManage' => !$record->trashed() && $this->actingUser($request)->can('update', $record),
        ]);
    }

    public function store(Request $request, CustomRecord $record, SaveRecordActivityAction $save, SourceLinkCatalog $sourceLinkCatalog): JsonResponse
    {
        $this->authorize('update', $record);

        $user = $this->actingUser($request);
        $activity = $save->execute($user, $record, $request->all());

        return RecordActivityResource::make($activity)->withLinks($sourceLinkCatalog->linksOf($user, $activity))->response()->setStatusCode(201);
    }

    public function update(Request $request, CustomRecord $record, RecordActivity $activity, SaveRecordActivityAction $save, SourceLinkCatalog $sourceLinkCatalog): RecordActivityResource
    {
        $this->authorize('update', $record);
        abort_unless($activity->record_id === $record->id, 404);

        $user = $this->actingUser($request);
        $saved = $save->execute($user, $record, $request->all(), $activity);

        return RecordActivityResource::make($saved)->withLinks($sourceLinkCatalog->linksOf($user, $saved));
    }

    public function destroy(CustomRecord $record, RecordActivity $activity, DeleteRecordActivityAction $delete): JsonResponse
    {
        $this->authorize('update', $record);
        abort_unless($activity->record_id === $record->id, 404);
        $delete->execute($activity);

        return new JsonResponse(status: 204);
    }
}
