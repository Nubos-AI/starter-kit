<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Exceptions\Authorization\RoleInUseException;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\User;

class RoleDeletionGuard
{
    /**
     * @throws RoleInUseException
     */
    public function assertRoleIsUnused(Role $role): void
    {
        $exception = $this->inUse($this->assignedUserCount($role), $this->assignedTeamCount($role));

        if ($exception instanceof RoleInUseException) {
            throw $exception;
        }
    }

    public function isUnused(Role $role): bool
    {
        return $this->assignedUserCount($role) === 0
            && $this->assignedTeamCount($role) === 0;
    }

    public function blockReason(int $userCount, int $teamCount): ?string
    {
        return $this->inUse($userCount, $teamCount)?->getMessage();
    }

    public function assignedUserCount(Role $role): int
    {
        return $this->holderCount($role, (new User)->getMorphClass());
    }

    public function assignedTeamCount(Role $role): int
    {
        return $this->holderCount($role, (new Team)->getMorphClass());
    }

    /**
     * @return array<string, int>
     */
    public function userCountsByRole(): array
    {
        return $this->holderCountsByRole((new User)->getMorphClass());
    }

    /**
     * @return array<string, int>
     */
    public function teamCountsByRole(): array
    {
        return $this->holderCountsByRole((new Team)->getMorphClass());
    }

    private function inUse(int $userCount, int $teamCount): ?RoleInUseException
    {
        if ($userCount > 0) {
            return RoleInUseException::hasAssignedUsers($userCount);
        }

        if ($teamCount > 0) {
            return RoleInUseException::hasAssignedTeams($teamCount);
        }

        return null;
    }

    private function holderCount(Role $role, string $morphClass): int
    {
        return RoleAssignment::query()
            ->where('role_id', $role->getKey())
            ->where('model_type', $morphClass)
            ->distinct()
            ->count('model_id');
    }

    /**
     * @return array<string, int>
     */
    private function holderCountsByRole(string $morphClass): array
    {
        return RoleAssignment::query()
            ->where('model_type', $morphClass)
            ->groupBy('role_id')
            ->select('role_id')
            ->selectRaw('COUNT(DISTINCT model_id) as aggregate')
            ->pluck('aggregate', 'role_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }
}
