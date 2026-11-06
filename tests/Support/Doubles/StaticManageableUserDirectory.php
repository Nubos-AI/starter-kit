<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\User;
use App\Support\Users\ManageableUserDirectory;

class StaticManageableUserDirectory extends ManageableUserDirectory
{
    /**
     * @var array<string, list<string>>
     */
    private array $roleIds = [];

    /**
     * @var list<string>
     */
    private array $escalated = [];

    /**
     * @var array<string, list<string>>
     */
    private array $teamIds = [];

    /**
     * @param  list<string>  $roleIds
     */
    public function withRoles(User $user, array $roleIds): self
    {
        $this->roleIds[(string) $user->getKey()] = $roleIds;

        return $this;
    }

    public function withEscalation(User $user): self
    {
        $this->escalated[] = (string) $user->getKey();

        return $this;
    }

    /**
     * @param  list<string>  $teamIds
     */
    public function withTeams(User $user, array $teamIds): self
    {
        $this->teamIds[(string) $user->getKey()] = $teamIds;

        return $this;
    }

    /**
     * @param  list<string>  $userKeys
     * @return array<string, list<string>>
     */
    public function roleIdsByUser(array $userKeys): array
    {
        return array_intersect_key($this->roleIds, array_flip($userKeys));
    }

    /**
     * @param  list<string>  $userKeys
     * @return list<string>
     */
    public function escalatedUserKeys(array $userKeys): array
    {
        return array_values(array_intersect($this->escalated, $userKeys));
    }

    /**
     * @param  list<string>  $userKeys
     * @return array<string, list<string>>
     */
    public function teamIdsByUser(array $userKeys): array
    {
        return array_intersect_key($this->teamIds, array_flip($userKeys));
    }
}
