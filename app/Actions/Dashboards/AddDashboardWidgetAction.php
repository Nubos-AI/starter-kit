<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Support\Reports\WidgetSourceResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class AddDashboardWidgetAction
{
    public function __construct(private readonly WidgetSourceResolver $sourceResolver) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, Dashboard $dashboard, array $input): DashboardWidget
    {
        Gate::forUser($actor)->authorize('update', $dashboard);

        $validated = Validator::make($input, $this->sourceResolver->rules())->validate();

        $source = $this->sourceResolver->resolve($actor, $validated);

        return DB::transaction(function () use ($dashboard, $validated, $source): DashboardWidget {
            $highest = DashboardWidget::query()
                ->where('dashboard_id', $dashboard->getKey())
                ->max('position');

            return DashboardWidget::query()->create([
                'tenant_id' => $dashboard->tenant_id,
                'dashboard_id' => $dashboard->getKey(),
                'report_id' => $source['report_id'],
                'goal_id' => $source['goal_id'],
                'title' => $validated['title'] ?? null,
                'chart_type' => $validated['chart_type'] ?? null,
                'definition' => $source['definition'],
                'position' => $highest === null ? 0 : (int) $highest + 1,
                'column_span' => (int) ($validated['column_span'] ?? 1),
            ]);
        });
    }
}
