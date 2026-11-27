<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Models\Dashboard;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateDashboardAction
{
    public function __construct(private readonly AdminArtifactAuditor $auditor) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $owner, array $input): Dashboard
    {
        Gate::forUser($owner)->authorize('create', Dashboard::class);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_tenant_wide' => ['sometimes', 'boolean'],
        ])->validate();

        $isTenantWide = (bool) ($validated['is_tenant_wide'] ?? false);

        if ($isTenantWide && !$owner->isEscalatedAuthority()) {
            throw ValidationException::withMessages([
                'is_tenant_wide' => __('i18n.backend.actions.dashboards.create_dashboard_action.only_an_elevated_role_may_make_a_dashboard_visible'),
            ]);
        }

        $tenantId = TenantContext::currentId((string) $owner->tenant_id);

        return DB::transaction(function () use ($owner, $tenantId, $validated, $isTenantWide): Dashboard {
            $dashboard = Dashboard::query()->create([
                'tenant_id' => $tenantId,
                'owner_id' => $owner->getKey(),
                'is_tenant_wide' => $isTenantWide,
                'name' => (string) $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            if ($isTenantWide) {
                $this->auditor->record(
                    $dashboard,
                    ['is_tenant_wide' => null],
                    ['is_tenant_wide' => true],
                );
            }

            return $dashboard;
        });
    }
}
