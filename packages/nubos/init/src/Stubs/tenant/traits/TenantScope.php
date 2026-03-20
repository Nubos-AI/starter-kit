<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait TenantScope
{
    public static function bootTenantScope(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenant = app()->bound('current_tenant') ? app('current_tenant') : null;

            if ($tenant instanceof Tenant) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenant->id);
            }
        });

        static::creating(function (Model $model): void {
            $tenant = app()->bound('current_tenant') ? app('current_tenant') : null;

            if ($tenant instanceof Tenant && !$model->getAttribute('tenant_id')) {
                $model->setAttribute('tenant_id', $tenant->id);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope('tenant');
    }
}
