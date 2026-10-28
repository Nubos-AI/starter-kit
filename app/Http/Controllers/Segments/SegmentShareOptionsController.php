<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Http\Controllers\Abstracts\Controller;
use App\Support\Segments\ManageableSegmentResolver;
use App\Support\Sharing\ShareGranteeOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SegmentShareOptionsController extends Controller
{
    public function __construct(
        private readonly ManageableSegmentResolver $manageableSegments,
        private readonly ShareGranteeOptions $granteeOptions,
    ) {}

    public function __invoke(Request $request, string $segment): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $user = $this->actingUser($request);

        $this->manageableSegments->resolveAuthorized($user, $segment, 'share');

        return new JsonResponse([
            'options' => $this->granteeOptions->forUser($user, trim((string) ($validated['q'] ?? ''))),
        ]);
    }
}
