<?php

declare(strict_types=1);

namespace App\Support\Governance;

use Carbon\CarbonImmutable;

class AbsenceDelegationResolver
{
    public function __construct(
        private readonly AbsenceDelegationDirectory $absenceDelegationDirectory,
    ) {}

    public function delegateFor(string $userId, CarbonImmutable $at): ?string
    {
        return $this->absenceDelegationDirectory->delegateIdCovering($userId, $at->setTimezone('UTC'));
    }

    /**
     * @return list<string>
     */
    public function delegatorsFor(string $delegateId, CarbonImmutable $at): array
    {
        return $this->distinct(
            $this->absenceDelegationDirectory->delegatorIdsCovering($delegateId, $at->setTimezone('UTC')),
        );
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function absentUserIds(array $userIds, CarbonImmutable $at): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->distinct(
            $this->absenceDelegationDirectory->coveredUserIds($userIds, $at->setTimezone('UTC')),
        );
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function distinct(array $ids): array
    {
        return array_values(array_unique($ids));
    }
}
