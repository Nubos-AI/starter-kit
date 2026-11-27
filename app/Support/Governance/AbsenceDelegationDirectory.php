<?php

declare(strict_types=1);

namespace App\Support\Governance;

use App\Models\AbsenceDelegation;
use Carbon\CarbonImmutable;

class AbsenceDelegationDirectory
{
    public function delegateIdCovering(string $userId, CarbonImmutable $moment): ?string
    {
        $delegateId = AbsenceDelegation::query()
            ->where('user_id', $userId)
            ->coveringAt($moment)
            ->orderBy('starts_at')
            ->value('delegate_id');

        return $delegateId === null ? null : (string) $delegateId;
    }

    /**
     * @return list<string>
     */
    public function delegatorIdsCovering(string $delegateId, CarbonImmutable $moment): array
    {
        /** @var list<string> $userIds */
        $userIds = AbsenceDelegation::query()
            ->where('delegate_id', $delegateId)
            ->coveringAt($moment)
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $userIds;
    }

    /**
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function coveredUserIds(array $userIds, CarbonImmutable $moment): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var list<string> $covered */
        $covered = AbsenceDelegation::query()
            ->whereIn('user_id', $userIds)
            ->coveringAt($moment)
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $covered;
    }
}
