<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboards;

use App\Actions\Dashboards\SetDefaultDashboardAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DefaultDashboardSelectionController extends Controller
{
    public function __construct(private readonly SetDefaultDashboardAction $setDefaultDashboard) {}

    public function __invoke(Request $request, string $dashboard): RedirectResponse
    {
        $user = $this->actingUser($request);

        $this->setDefaultDashboard->execute($user, $this->resolveDashboard($user, $dashboard));

        return back();
    }

    private function resolveDashboard(User $user, string $dashboard): Dashboard
    {
        return Dashboard::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereKey($dashboard)
            ->firstOrFail();
    }
}
