<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboards;

use App\Actions\Dashboards\RevokeDashboardShareAction;
use App\Actions\Dashboards\ShareDashboardAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Dashboards\DashboardShareResource;
use App\Models\Dashboard;
use App\Models\DashboardShare;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardSharesController extends Controller
{
    public function __construct(
        private readonly ShareDashboardAction $shareDashboard,
        private readonly RevokeDashboardShareAction $revokeShare,
    ) {}

    public function index(Request $request, string $dashboard): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveDashboard($user, $dashboard);

        $this->authorize('share', $model);

        $shares = DashboardShare::query()
            ->where('dashboard_id', $model->getKey())
            ->with('grantee')
            ->get();

        return new JsonResponse([
            'data' => DashboardShareResource::collection($shares)->resolve($request),
        ]);
    }

    public function store(Request $request, string $dashboard): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveDashboard($user, $dashboard);

        $this->authorize('share', $model);

        $share = $this->shareDashboard->execute($model, $request->all(), $user);

        return new JsonResponse([
            'data' => (new DashboardShareResource($share))->resolve($request),
        ], 201);
    }

    public function destroy(Request $request, string $dashboard, string $share): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveDashboard($user, $dashboard);

        $this->authorize('share', $model);

        $shareModel = DashboardShare::query()->whereKey($share)->firstOrFail();

        if ($shareModel->dashboard_id !== $model->getKey()) {
            throw (new ModelNotFoundException)->setModel(DashboardShare::class);
        }

        $this->revokeShare->execute($user, $shareModel);

        return new JsonResponse(null, 204);
    }

    private function resolveDashboard(User $user, string $dashboard): Dashboard
    {
        return Dashboard::query()
            ->where('tenant_id', $user->tenant_id)
            ->with('shares')
            ->whereKey($dashboard)
            ->firstOrFail();
    }
}
