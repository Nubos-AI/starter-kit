<?php

declare(strict_types=1);

namespace App\Support\Scim;

use App\DTOs\Scim\ScimFilter;
use App\Enums\Sso\IdentityProvider;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ScimUserDirectory
{
    /**
     * @var list<string>
     */
    public static array $filterableAttributes = ['username', 'externalid', 'emails.value'];

    /**
     * @return Builder<User>
     */
    public function query(string $tenantId, ?ScimFilter $filter = null): Builder
    {
        $query = User::query()
            ->where('users.tenant_id', $tenantId)
            ->where('users.is_service', false);

        if ($filter === null) {
            return $query;
        }

        if ($filter->attribute === 'externalid') {
            return $query->whereHas('identities', static function (Builder $identities) use ($tenantId, $filter): void {
                $identities
                    ->withoutGlobalScope(TenantScope::class)
                    ->where('user_identities.tenant_id', $tenantId)
                    ->where('provider', IdentityProvider::Scim->value)
                    ->where('subject', $filter->value);
            });
        }

        return $query->where('users.email', Str::lower($filter->value));
    }

    /**
     * @return array{0: int, 1: Collection<int, User>}
     */
    public function page(string $tenantId, ?ScimFilter $filter, int $offset, int $limit): array
    {
        $query = $this->query($tenantId, $filter);
        $total = (clone $query)->count();

        if ($limit === 0) {
            return [$total, new Collection];
        }

        return [$total, $this->withRelations($query)->orderBy('users.id')->offset($offset)->limit($limit)->get()];
    }

    public function find(string $tenantId, string $userId): ?User
    {
        if (!Str::isUlid($userId)) {
            return null;
        }

        return $this->withRelations($this->query($tenantId))->whereKey($userId)->first();
    }

    /**
     * @param  list<string>  $userIds
     * @return Collection<int, User>
     */
    public function findMany(string $tenantId, array $userIds): Collection
    {
        if ($userIds === []) {
            return new Collection;
        }

        return $this->query($tenantId)->whereKey($userIds)->get();
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function withRelations(Builder $query): Builder
    {
        return $query->with([
            'identities' => static fn ($identities) => $identities->withoutGlobalScope(TenantScope::class)->where('provider', IdentityProvider::Scim->value),
            'teams',
        ]);
    }
}
