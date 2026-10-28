<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Models\Abstracts\TypedRecord;
use App\Models\CustomRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<CustomRecord>
 */
class ObjectTypeScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!$model instanceof TypedRecord) {
            return;
        }

        $builder->where(
            $model->getTable().'.object_type_id',
            $model::boundObjectType()->getKey(),
        );
    }
}
