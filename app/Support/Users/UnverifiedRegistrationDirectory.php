<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Enums\Users\UserStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class UnverifiedRegistrationDirectory
{
    /**
     * @return list<string>
     */
    public function abandonedIds(CarbonInterface $cutoff, int $limit): array
    {
        /** @var list<string> $ids */
        $ids = $this->abandonedQuery($cutoff)
            ->limit($limit)
            ->pluck('users.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }

    /**
     * @return Builder<User>
     */
    public function abandonedQuery(CarbonInterface $cutoff): Builder
    {
        return User::query()
            ->select('users.id')
            ->where('users.status', UserStatus::Accepted->value)
            ->whereNull('users.email_verified_at')
            ->where('users.is_service', false)
            ->whereNull('users.invited_by_id')
            ->whereNotNull('users.tenant_id')
            ->where('users.created_at', '<', $cutoff)
            ->whereNotExists(static function (QueryBuilder $members): void {
                $members->from('users', 'members')
                    ->whereColumn('members.tenant_id', 'users.tenant_id')
                    ->whereColumn('members.id', '<>', 'users.id');
            })
            ->orderBy('users.created_at');
    }

    public function isSoleMemberOfTenant(User $user): bool
    {
        return !User::withTrashed()
            ->where('tenant_id', $user->tenant_id)
            ->whereKeyNot($user->getKey())
            ->exists();
    }
}
