<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

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

                return;
            }

            if (app()->runningInConsole()) {
                throw new RuntimeException(
                    'No tenant context. Use withoutGlobalScope(\'tenant\') for admin queries.',
                );
            }

            abort(403, 'No tenant context.');
        });

        static::creating(function (Model $model): void {
            if (app()->bound('currentTenant') && $model->tenant_id === null) {
                $model->tenant_id = app('currentTenant')->id;
            }
        });
    }
}
