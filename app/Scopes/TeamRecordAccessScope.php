<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Authorization\RowAccess\RecordAccessRuleCompiler;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * @implements Scope<CustomRecord>
 */
class TeamRecordAccessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!app(RowAccessEnforcement::class)->isEnabled()) {
            return;
        }

        $user = Auth::user();

        if (!$user instanceof User) {
            return;
        }

        $rules = app(TeamAccessRuleResolver::class)->resolveFor($user);

        if ($rules->isUnrestricted()) {
            return;
        }

        app(RecordAccessRuleCompiler::class)->apply($builder, $rules, $model->getTable());
    }
}
