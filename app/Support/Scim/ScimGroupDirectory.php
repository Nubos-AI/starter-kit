<?php

declare(strict_types=1);

namespace App\Support\Scim;

use App\DTOs\Scim\ScimFilter;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ScimGroupDirectory
{
    /**
     * @var list<string>
     */
    public static array $filterableAttributes = ['displayname'];

    /**
     * @return Builder<Team>
     */
    public function query(string $tenantId, ?ScimFilter $filter = null): Builder
    {
        $query = Team::query()->where('teams.tenant_id', $tenantId);

        return $filter === null ? $query : $query->where('teams.name', $filter->value);
    }

    /**
     * @return array{0: int, 1: Collection<int, Team>}
     */
    public function page(string $tenantId, ?ScimFilter $filter, int $offset, int $limit): array
    {
        $query = $this->query($tenantId, $filter);
        $total = (clone $query)->count();

        if ($limit === 0) {
            return [$total, new Collection];
        }

        return [$total, $this->withMembers($query)->orderBy('teams.id')->offset($offset)->limit($limit)->get()];
    }

    public function find(string $tenantId, string $teamId): ?Team
    {
        if (!Str::isUlid($teamId)) {
            return null;
        }

        return $this->withMembers($this->query($tenantId))->whereKey($teamId)->first();
    }

    /**
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    private function withMembers(Builder $query): Builder
    {
        return $query->with(['users' => static fn ($users) => $users->where('users.is_service', false)]);
    }
}
