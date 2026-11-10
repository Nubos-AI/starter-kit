<?php

declare(strict_types=1);

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!app()->bound('current_tenant')) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(
            $model->getTable().'.tenant_id',
            app('current_tenant')->getKey(),
        );
    }
}
