<?php

declare(strict_types=1);

namespace App\Actions\Governance;

use App\Models\AbsenceDelegation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class DeleteAbsenceDelegationAction
{
    /**
     * @throws AuthorizationException
     * @throws Throwable
     */
    public function execute(User $actor, AbsenceDelegation $delegation): void
    {
        Gate::forUser($actor)->authorize('delete', $delegation);

        DB::transaction(function () use ($delegation, $actor): void {
            $delegation->fill(['deleted_by_id' => (string) $actor->getKey()]);

            $delegation->save();

            $delegation->delete();
        });
    }
}
