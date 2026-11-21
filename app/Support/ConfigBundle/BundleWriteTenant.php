<?php

declare(strict_types=1);

namespace App\Support\ConfigBundle;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\Scoped;

#[Scoped]
class BundleWriteTenant
{
    private ?string $targetTenantId = null;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     *
     * @throws AuthorizationException
     */
    public function within(string $targetTenantId, Closure $work): mixed
    {
        if ($this->targetTenantId !== null) {
            throw new AuthorizationException(__('i18n.backend.support.config_bundle.bundle_write_tenant.a_bundle_write_frame_is_already_open_a_second'));
        }

        if ($targetTenantId === '') {
            throw new AuthorizationException(__('i18n.backend.support.config_bundle.bundle_write_tenant.a_bundle_write_frame_needs_a_target_tenant'));
        }

        $this->targetTenantId = $targetTenantId;

        try {
            return $work();
        } finally {
            $this->targetTenantId = null;
        }
    }

    public function tenantIdFor(User $actingUser): ?string
    {
        return $this->targetTenantId ?? $actingUser->tenant_id;
    }
}
