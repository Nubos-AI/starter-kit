<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateDashboardLayoutAction
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, Dashboard $dashboard, array $input): void
    {
        Gate::forUser($actor)->authorize('update', $dashboard);

        $validated = Validator::make($input, [
            'widgets' => ['required', 'array'],
            'widgets.*.id' => ['required', 'string'],
            'widgets.*.column_span' => ['required', 'integer', 'between:1,3'],
        ])->validate();

        /** @var list<array{id: string, column_span: int}> $arrangement */
        $arrangement = array_values($validated['widgets']);

        $this->assertCoversEveryWidgetOnce($dashboard, $arrangement);

        DB::transaction(function () use ($dashboard, $arrangement): void {
            foreach ($arrangement as $position => $entry) {
                DashboardWidget::query()
                    ->where('dashboard_id', $dashboard->getKey())
                    ->whereKey($entry['id'])
                    ->update([
                        'position' => $position,
                        'column_span' => $entry['column_span'],
                    ]);
            }
        });
    }

    /**
     * @param  list<array{id: string, column_span: int}>  $arrangement
     *
     * @throws ValidationException
     */
    private function assertCoversEveryWidgetOnce(Dashboard $dashboard, array $arrangement): void
    {
        $submitted = array_map(static fn (array $entry): string => $entry['id'], $arrangement);

        /** @var list<string> $stored */
        $stored = DashboardWidget::query()
            ->where('dashboard_id', $dashboard->getKey())
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        sort($submitted);
        sort($stored);

        if ($submitted === $stored) {
            return;
        }

        throw ValidationException::withMessages([
            'widgets' => __('i18n.backend.actions.dashboards.update_dashboard_layout_action.the_arrangement_must_contain_every_widget_of_this_dashboard'),
        ]);
    }
}
