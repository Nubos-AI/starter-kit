<?php

declare(strict_types=1);

namespace App\Http\Resources\Dashboards;

use App\Enums\Dashboards\DashboardActionRefusalReason;
use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dashboard
 */
class DashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Dashboard $dashboard */
        $dashboard = $this->resource;

        $user = $request->user();

        $canView = $user instanceof User && $user->can('view', $dashboard);
        $canUpdate = $canView && $user->can('update', $dashboard);
        $canDelete = $canView && $user->can('delete', $dashboard);
        $canShare = $canView && $user->can('share', $dashboard);

        return [
            'id' => (string) $dashboard->getKey(),
            'name' => $dashboard->name,
            'description' => $dashboard->description,
            'owner_id' => $dashboard->owner_id,
            'is_owner' => $user instanceof User && $dashboard->owner_id === $user->getKey(),
            'is_tenant_wide' => $dashboard->is_tenant_wide,
            'is_default' => $user instanceof User && $user->default_dashboard_id === $dashboard->getKey(),
            'can_update' => $canUpdate,
            'can_delete' => $canDelete,
            'can_share' => $canShare,
            'update_reason' => $this->refusalReason($canUpdate, $canView)?->value,
            'delete_reason' => $this->refusalReason($canDelete, $canView)?->value,
            'share_reason' => $this->refusalReason($canShare, $canView)?->value,
            'has_definer_widget' => (bool) $dashboard->getAttribute('has_definer_widget'),
            'updated_at' => $dashboard->updated_at?->toIso8601String(),
        ];
    }

    private function refusalReason(bool $isAllowed, bool $canView): ?DashboardActionRefusalReason
    {
        if ($isAllowed) {
            return null;
        }

        return $canView
            ? DashboardActionRefusalReason::NotOwner
            : DashboardActionRefusalReason::NotVisible;
    }
}
