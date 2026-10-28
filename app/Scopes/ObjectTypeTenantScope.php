<?php

declare(strict_types=1);

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class ObjectTypeTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereHas('objectType');
    }
}
