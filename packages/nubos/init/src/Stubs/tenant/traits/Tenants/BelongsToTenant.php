<?php

declare(strict_types=1);

namespace App\Traits\Tenants;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait BelongsToTenant
{
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function currentTenant(): ?Tenant
    {
        return app()->bound('current_tenant') ? app('current_tenant') : null;
    }

    public function belongsToTenant(Tenant $tenant): bool
    {
        return $this->tenants()->where('tenants.id', $tenant->id)->exists();
    }
}
