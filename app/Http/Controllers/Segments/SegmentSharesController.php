<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Actions\Segments\RevokeSegmentShareAction;
use App\Actions\Segments\ShareSegmentAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\Segment;
use App\Models\SegmentShare;
use App\Support\Segments\ManageableSegmentResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SegmentSharesController extends Controller
{
    public function __construct(
        private readonly ShareSegmentAction $shareSegment,
        private readonly RevokeSegmentShareAction $revokeShare,
        private readonly ManageableSegmentResolver $manageableSegments,
    ) {}

    public function index(Request $request, string $segment): JsonResponse
    {
        $model = $this->manageableSegments->resolveAuthorized($this->actingUser($request), $segment, 'share');

        $shares = SegmentShare::query()
            ->where('segment_id', $model->getKey())
            ->get()
            ->map(fn (SegmentShare $share): array => $this->present($share))
            ->all();

        return new JsonResponse(['data' => $shares]);
    }

    public function store(Request $request, string $segment): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = Segment::query()->whereKey($segment)->firstOrFail();

        $share = $this->shareSegment->execute($model, $request->all(), $user);

        return new JsonResponse(['data' => $this->present($share)], 201);
    }

    public function destroy(Request $request, string $segment, string $share): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = Segment::query()->whereKey($segment)->firstOrFail();

        $shareModel = SegmentShare::query()->whereKey($share)->firstOrFail();

        if ($shareModel->segment_id !== $model->getKey()) {
            throw (new ModelNotFoundException)->setModel(SegmentShare::class);
        }

        $this->revokeShare->execute($user, $shareModel);

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SegmentShare $share): array
    {
        return [
            'id' => (string) $share->getKey(),
            'grantee_type' => $share->grantee_type,
            'grantee_id' => $share->grantee_id,
            'can_edit' => $share->can_edit,
        ];
    }
}
