<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Actions\Segments\SetAdminDefaultSegmentAction;
use App\Actions\Segments\SetSegmentDefaultAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\Segment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDefaultSegmentsController extends Controller
{
    public function __construct(
        private readonly SetAdminDefaultSegmentAction $setAdminDefault,
        private readonly SetSegmentDefaultAction $setDefault,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $segment = $this->setAdminDefault->execute($this->actingUser($request), $request->all());

        return new JsonResponse(['data' => $this->present($segment)]);
    }

    public function destroy(Request $request, string $segment): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = Segment::query()->whereKey($segment)->firstOrFail();

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
