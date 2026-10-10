<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\User;
use App\Support\Governance\CandidateDirectory;
use Illuminate\Support\Collection;

class StaticCandidateDirectory extends CandidateDirectory
{
    /**
     * @var list<array{roleIds: list<string>, tenantId: string, teamId: string|null}>
     */
    public array $roleLookups = [];

    /**
     * @var list<array{teamIds: list<string>, tenantId: string}>
     */
    public array $teamLookups = [];

    /**
     * @var list<array{userIds: list<string>, tenantId: string}>
     */
    public array $userLookups = [];

    /**
     * @var array<string, list<string>>
     */
    private array $holderIdsByRole = [];

    /**
     * @var array<string, list<string>>
     */
    private array $memberIdsByTeam = [];

    /**
     * @var array<string, User>
     */
    private array $knownUsers = [];

    /**
     * @var list<string>
     */
    private array $teamScopedRoleHolders = [];

    public static function empty(): self
    {
        $directory = new self;

        app()->instance(CandidateDirectory::class, $directory);

        return $directory;
    }

    /**
     * @param  list<string>  $userIds
     */
    public function withRoleHolders(string $roleId, array $userIds): self
    {
        $this->holderIdsByRole[$roleId] = $userIds;

        return $this;
    }

    /**
     * @param  list<string>  $userIds
     */
    public function withTeamScopedRoleHolders(array $userIds): self
    {
        $this->teamScopedRoleHolders = $userIds;

        return $this;
    }

    /**
     * @param  list<string>  $userIds
     */
    public function withTeamMembers(string $teamId, array $userIds): self
    {
        $this->memberIdsByTeam[$teamId] = $userIds;

        return $this;
    }

    /**
     * @param  list<User>  $users
     */
    public function withActiveUsers(array $users): self
    {
        foreach ($users as $user) {
            $this->knownUsers[(string) $user->getKey()] = $user;
        }

        return $this;
    }

    public function roleHolderIds(array $roleIds, string $tenantId, ?string $teamId): array
    {
        $this->roleLookups[] = ['roleIds' => $roleIds, 'tenantId' => $tenantId, 'teamId' => $teamId];

        if ($roleIds === []) {
            return [];
        }

        $holderIds = [];

        foreach ($roleIds as $roleId) {
            foreach ($this->holderIdsByRole[$roleId] ?? [] as $holderId) {
                $holderIds[] = $holderId;
            }
        }

        if ($teamId !== null) {
            $holderIds = [...$holderIds, ...$this->teamScopedRoleHolders];
        }

        return array_values(array_unique($holderIds));
    }

    public function memberIdsOfTeams(array $teamIds, string $tenantId): array
    {
        $this->teamLookups[] = ['teamIds' => $teamIds, 'tenantId' => $tenantId];

        $memberIds = [];

        foreach ($teamIds as $teamId) {
            foreach ($this->memberIdsByTeam[$teamId] ?? [] as $memberId) {
                $memberIds[] = $memberId;
            }
        }

        return array_values(array_unique($memberIds));
    }

    /**
     * @return Collection<int, User>
     */
    public function activeUsers(array $userIds, string $tenantId): Collection
    {
        $this->userLookups[] = ['userIds' => $userIds, 'tenantId' => $tenantId];

        $resolved = [];

        foreach ($userIds as $userId) {
            $user = $this->knownUsers[$userId] ?? null;

            if ($user instanceof User && (string) $user->tenant_id === $tenantId) {
                $resolved[] = $user;
            }
        }

        return new Collection($resolved);
    }

    /**
     * @return list<string>
     */
    public function lastRequestedUserIds(): array
    {
        $last = end($this->userLookups);

        return $last === false ? [] : $last['userIds'];
    }

    /**
     * @return list<string>
     */
    public function requestedTenantIds(): array
    {
        $tenantIds = [];

        foreach ([...$this->roleLookups, ...$this->teamLookups, ...$this->userLookups] as $lookup) {
            $tenantIds[] = $lookup['tenantId'];
        }

        return array_values(array_unique($tenantIds));
    }
}
