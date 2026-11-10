<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Abstracts\Controller;
use App\Support\Dashboards\DashboardWidgetLocator;
use App\Support\Sharing\ShareGranteeOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardShareOptionsController extends Controller
{
    public function __construct(
        private readonly DashboardWidgetLocator $locator,
        private readonly ShareGranteeOptions $granteeOptions,
    ) {}

    public function __invoke(Request $request, string $dashboard): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $user = $this->actingUser($request);
        $model = $this->locator->resolveDashboard($user, $dashboard);

        $this->authorize('share', $model);

        return response()->json([
            'options' => $this->granteeOptions->forUser($user, trim((string) ($validated['q'] ?? ''))),
        ]);
    }
}
