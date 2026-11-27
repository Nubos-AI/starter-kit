<?php

declare(strict_types=1);

namespace App\Support\Approvals;

use App\Models\ApprovalProcess;
use App\Models\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TenantApprovalProcessResolver
{
    public function __construct(private readonly Repository $config) {}

    public function resolve(User $user, string $approvalProcessId): ApprovalProcess
    {
        $eagerLoads = $this->config->get('modules.approvals.eager_loads', []);

        return ApprovalProcess::query()
            ->where('tenant_id', $user->tenant_id)
            ->with([
                'stages',
                'anchor',
                'record' => static fn (Relation $query) => $query->withoutGlobalScope(SoftDeletingScope::class)->with('objectType'),
                ...(is_array($eagerLoads) ? $eagerLoads : []),
            ])
            ->whereKey($approvalProcessId)
            ->firstOrFail();
    }
}
