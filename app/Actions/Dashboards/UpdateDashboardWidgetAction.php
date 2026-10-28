<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Models\DashboardWidget;
use App\Models\User;
use App\Support\Reports\WidgetSourceResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateDashboardWidgetAction
{
    public function __construct(private readonly WidgetSourceResolver $sourceResolver) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, DashboardWidget $widget, array $input): DashboardWidget
    {
        Gate::forUser($actor)->authorize('update', $widget);

        $validated = Validator::make($input, $this->sourceResolver->rules())->validate();

        $source = $this->sourceResolver->resolve($actor, $validated);

        return DB::transaction(function () use ($widget, $validated, $source): DashboardWidget {
            $widget->fill([
                'report_id' => $source['report_id'],
                'goal_id' => $source['goal_id'],
                'title' => $validated['title'] ?? null,
                'chart_type' => $validated['chart_type'] ?? null,
                'definition' => $source['definition'],
                'column_span' => (int) ($validated['column_span'] ?? $widget->column_span),
            ]);

            $widget->save();

            return $widget;
        });
    }
}
