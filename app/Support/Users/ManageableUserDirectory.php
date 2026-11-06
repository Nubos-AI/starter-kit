<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Http\IdentifierList;
use Illuminate\Support\Collection;

class ManageableUserDirectory
{
    /**
     * @param  list<string>  $userKeys
     * @return array<string, list<string>>
     */
    public function roleIdsByUser(array $userKeys): array
    {
        return RoleAssignment::query()
            ->where('model_type', (new User)->getMorphClass())
            ->whereIn('model_id', $userKeys)
            ->get(['model_id', 'role_id'])
            ->groupBy('model_id')
            ->map(static fn (Collection $rows): array => IdentifierList::from($rows->pluck('role_id')->all()))
            ->all();
    }

    /**
     * @param  list<string>  $userKeys
     * @return list<string>
     */
    public function escalatedUserKeys(array $userKeys): array
    {
        return RoleAssignment::query()
            ->where('model_type', (new User)->getMorphClass())
            ->whereIn('model_id', $userKeys)
            ->whereIn('role_id', Role::query()->whereNotNull('authority')->select('id'))
            ->pluck('model_id')
            ->pipe(static fn (Collection $keys): array => IdentifierList::from($keys->all()));
    }

    /**
     * @param  list<string>  $userKeys
     * @return array<string, list<string>>
     */
    public function teamIdsByUser(array $userKeys): array
    {
        return User::query()
            ->whereKey($userKeys)
            ->with('teams:id')
            ->get(['id'])
            ->mapWithKeys(static fn (User $user): array => [
                (string) $user->getKey() => IdentifierList::from($user->teams->modelKeys()),
            ])
            ->all();
    }
}
