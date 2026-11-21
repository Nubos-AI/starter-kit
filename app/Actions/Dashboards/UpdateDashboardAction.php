<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Models\Dashboard;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateDashboardAction
{
    public function __construct(private readonly AdminArtifactAuditor $auditor) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, Dashboard $dashboard, array $input): Dashboard
    {
        Gate::forUser($actor)->authorize('update', $dashboard);

        $validated = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_tenant_wide' => ['sometimes', 'boolean'],
        ])->validate();

        $previous = $dashboard->is_tenant_wide;

        $isTenantWide = array_key_exists('is_tenant_wide', $validated)
            ? (bool) $validated['is_tenant_wide']
            : $previous;

        if ($isTenantWide !== $previous && !$actor->isEscalatedAuthority()) {
            throw ValidationException::withMessages([
                'is_tenant_wide' => __('i18n.backend.actions.dashboards.update_dashboard_action.only_an_elevated_role_may_change_whether_a_dashboard'),
            ]);
        }

        $attributes = ['is_tenant_wide' => $isTenantWide];

        if (array_key_exists('name', $validated)) {
            $attributes['name'] = (string) $validated['name'];
        }

        if (array_key_exists('description', $validated)) {
            $attributes['description'] = $validated['description'];
        }

        return DB::transaction(function () use ($dashboard, $attributes, $isTenantWide, $previous): Dashboard {
            $dashboard->fill($attributes);

            $dashboard->save();

            if ($isTenantWide !== $previous) {
                $this->auditor->record(
                    $dashboard,
                    ['is_tenant_wide' => $previous],
                    ['is_tenant_wide' => $isTenantWide],
                );
            }

            return $dashboard;
        });
    }
}
