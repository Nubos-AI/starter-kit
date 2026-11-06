<?php

declare(strict_types=1);

namespace App\Http\Resources\Dashboards;

use App\Enums\Sharing\ShareGranteeType;
use App\Models\DashboardShare;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DashboardShare
 */
class DashboardShareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DashboardShare $share */
        $share = $this->resource;

        $grantee = $share->grantee;

        return [
            'id' => (string) $share->getKey(),
            'grantee_type' => ShareGranteeType::fromModelClass($share->grantee_type)?->value,
            'grantee_id' => $share->grantee_id,
            'grantee_name' => $grantee instanceof Model ? $this->granteeName($grantee) : null,
            'can_edit' => $share->can_edit,
            'granted_at' => $share->created_at?->toIso8601String(),
        ];
    }

    private function granteeName(Model $grantee): ?string
    {
        $name = $grantee->getAttribute('name');

        return is_string($name) ? $name : null;
    }
}
