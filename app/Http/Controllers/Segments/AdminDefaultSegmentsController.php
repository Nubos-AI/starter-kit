<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Actions\Segments\SetAdminDefaultSegmentAction;
use App\Actions\Segments\SetSegmentDefaultAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\Segment;
use App\Support\Segments\ManageableSegmentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDefaultSegmentsController extends Controller
{
    public function __construct(
        private readonly SetAdminDefaultSegmentAction $setAdminDefault,
        private readonly SetSegmentDefaultAction $setDefault,
        private readonly ManageableSegmentResolver $manageableSegments,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);
        $segmentId = $request->input('segment_id');

        $this->manageableSegments->resolveAuthorized($user, is_string($segmentId) ? $segmentId : '', 'manageDefaults');

        $segment = $this->setAdminDefault->execute($user, $request->all());

        return new JsonResponse(['data' => $this->present($segment)]);
    }

    public function destroy(Request $request, string $segment): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->manageableSegments->resolveAuthorized($user, $segment, 'manageDefaults');

        $this->setDefault->execute($user, $model, false);

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Segment $segment): array
    {
        return [
            'id' => (string) $segment->getKey(),
            'object_type_id' => $segment->object_type_id,
            'is_default' => $segment->is_default,
        ];
    }
}
