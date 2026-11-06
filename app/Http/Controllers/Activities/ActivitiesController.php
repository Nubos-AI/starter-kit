<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activities;

use App\Actions\Activities\DeleteRecordActivityAction;
use App\Actions\Activities\SaveRecordActivityAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\RecordActivityResource;
use App\Models\RecordActivity;
use App\Support\Links\SourceLinkCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivitiesController extends Controller
{
    public function __construct(private readonly SourceLinkCatalog $sourceLinkCatalog) {}

    public function store(Request $request, SaveRecordActivityAction $save): JsonResponse
    {
        $this->authorize('create', RecordActivity::class);

        $activity = $save->execute($this->actingUser($request), null, $request->all());

        return $this->activityResponse($request, $activity, 201);
    }

    public function update(Request $request, RecordActivity $activity, SaveRecordActivityAction $save): JsonResponse
    {
        $this->authorize('update', $activity);
        abort_unless($activity->record_id === null, 404);

        $saved = $save->execute($this->actingUser($request), null, $request->all(), $activity);

        return $this->activityResponse($request, $saved);
    }

    public function destroy(RecordActivity $activity, DeleteRecordActivityAction $delete): JsonResponse
    {
        $this->authorize('delete', $activity);
        abort_unless($activity->record_id === null, 404);

        $delete->execute($activity);

        return new JsonResponse(status: 204);
    }

    private function activityResponse(Request $request, RecordActivity $activity, int $status = 200): JsonResponse
    {
        $links = $this->sourceLinkCatalog->linksOf($this->actingUser($request), $activity);

        return new JsonResponse(
            ['data' => RecordActivityResource::make($activity)->withLinks($links)->resolve($request)],
            $status,
        );
    }
}
