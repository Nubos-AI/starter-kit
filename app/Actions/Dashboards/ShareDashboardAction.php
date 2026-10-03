<?php

declare(strict_types=1);

namespace App\Actions\Dashboards;

use App\Enums\Sharing\ShareGranteeType;
use App\Models\Dashboard;
use App\Models\DashboardShare;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShareDashboardAction
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(Dashboard $dashboard, array $input, User $grantedBy): DashboardShare
    {
        Gate::forUser($grantedBy)->authorize('share', $dashboard);

        $validated = Validator::make($input, [
            'grantee_type' => ['required', 'string', Rule::in(array_column(ShareGranteeType::cases(), 'value'))],
            'grantee_id' => ['required', 'string'],
            'can_edit' => ['sometimes', 'boolean'],
        ])->validate();

        $granteeType = ShareGranteeType::from((string) $validated['grantee_type']);
        $granteeId = (string) $validated['grantee_id'];

        if (!$this->granteeExistsInTenant($granteeType, $granteeId)) {
            throw (new ModelNotFoundException)->setModel($granteeType->modelClass());
        }

        return DashboardShare::query()->updateOrCreate(
            [
                'dashboard_id' => $dashboard->getKey(),
                'grantee_type' => $granteeType->modelClass(),
                'grantee_id' => $granteeId,
            ],
            [
                'tenant_id' => $dashboard->tenant_id,
                'can_edit' => (bool) ($validated['can_edit'] ?? false),
                'granted_by' => $grantedBy->getKey(),
            ],
        );
    }

    private function granteeExistsInTenant(ShareGranteeType $granteeType, string $granteeId): bool
    {
        $tenantId = TenantContext::currentId();

        if ($tenantId === null) {
            return false;
        }

        return match ($granteeType) {
            ShareGranteeType::User => User::query()
                ->whereKey($granteeId)
                ->where('tenant_id', $tenantId)
                ->exists(),
            ShareGranteeType::Team => Team::query()
                ->whereKey($granteeId)
                ->where('tenant_id', $tenantId)
                ->exists(),
            ShareGranteeType::Role => Role::query()
                ->whereKey($granteeId)
                ->exists(),
        };
    }
}
