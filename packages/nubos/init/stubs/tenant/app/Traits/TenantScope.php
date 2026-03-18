<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait TenantScope
{
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function bootTenantScope(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            if (app()->bound('currentTenant')) {
                $query->where($query->getModel()->getTable() . '.tenant_id', app('currentTenant')->id);
            }
        });

        static::creating(function (Model $model): void {
            if (app()->bound('currentTenant') && !$model->tenant_id) {
                $model->tenant_id = app('currentTenant')->id;
            }
        });
    }
}
