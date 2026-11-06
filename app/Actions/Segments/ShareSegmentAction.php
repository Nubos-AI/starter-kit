<?php

declare(strict_types=1);

namespace App\Actions\Segments;

use App\Models\Role;
use App\Models\Segment;
use App\Models\SegmentShare;
use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShareSegmentAction
{
    /**
     * @var array<string, class-string<Model>>
     */
    private array $granteeTypes = [
        'team' => Team::class,
        'role' => Role::class,
        'user' => User::class,
    ];

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(Segment $segment, array $input, User $grantedBy): SegmentShare
    {
        Gate::forUser($grantedBy)->authorize('share', $segment);

        $validated = Validator::make($input, [
            'grantee_type' => ['required', 'string', Rule::in(array_keys($this->granteeTypes))],
            'grantee_id' => ['required', 'string'],
            'can_edit' => ['sometimes', 'boolean'],
        ])->validate();

        $granteeType = (string) $validated['grantee_type'];
        $granteeId = (string) $validated['grantee_id'];
        $morphClass = $this->granteeTypes[$granteeType];

        if (!$this->granteeExistsInTenant($granteeType, $granteeId)) {
            throw (new ModelNotFoundException)->setModel($morphClass);
        }

        return SegmentShare::query()->firstOrCreate(
            [
                'segment_id' => $segment->getKey(),
                'grantee_type' => $morphClass,
                'grantee_id' => $granteeId,
            ],
            [
                'can_edit' => (bool) ($validated['can_edit'] ?? false),
                'granted_by' => $grantedBy->getKey(),
            ],
        );
    }

    private function granteeExistsInTenant(string $granteeType, string $granteeId): bool
    {
        $tenantId = TenantContext::currentId();

        if ($tenantId === null) {
            return false;
        }

        return match ($granteeType) {
            'user' => User::query()
                ->whereKey($granteeId)
                ->where('tenant_id', $tenantId)
                ->exists(),
            'team' => Team::query()
                ->whereKey($granteeId)
                ->where('tenant_id', $tenantId)
                ->exists(),
            'role' => Role::query()
                ->whereKey($granteeId)
                ->exists(),
            default => false,
        };
    }
}
