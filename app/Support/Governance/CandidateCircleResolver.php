<?php

declare(strict_types=1);

namespace App\Support\Governance;

use App\DTOs\Governance\CandidateCircle;
use App\Enums\Engine\SystemFilterField;
use App\Enums\Governance\CandidateSource;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Http\IdentifierList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CandidateCircleResolver
{
    public function __construct(
        private readonly AbsenceDelegationResolver $absenceDelegationResolver,
        private readonly CandidateDirectory $candidateDirectory,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function resolve(
        CustomRecord $record,
        CandidateCircle $circle,
        CarbonImmutable $at,
        bool $excludeAbsent = true,
    ): Collection {
        $tenantId = $record->tenant_id;

        /** @var Collection<int, string> $collected */
        $collected = (new Collection)
            ->merge($this->roleUserIds($circle, $tenantId, $record->team_id))
            ->merge($this->candidateDirectory->memberIdsOfTeams($this->teamIdsFor($circle, $record), $tenantId))
            ->merge($this->fieldUserIds($circle, $record))
            ->merge($circle->hasSource(CandidateSource::FixedList) ? $circle->userIds : []);

        $users = $this->candidateDirectory->activeUsers($this->distinct($collected), $tenantId);

        return $excludeAbsent ? $this->withoutAbsent($users, $at) : $users;
    }

    /**
     * @return Collection<int, User>
     */
    public function resolveWithoutRecord(
        CandidateCircle $circle,
        string $tenantId,
        CarbonImmutable $at,
        bool $excludeAbsent = true,
    ): Collection {
        /** @var Collection<int, string> $collected */
        $collected = (new Collection)
            ->merge($this->roleUserIds($circle, $tenantId, null))
            ->merge($this->candidateDirectory->memberIdsOfTeams(
                $circle->hasSource(CandidateSource::Team) ? $circle->teamIds : [],
                $tenantId,
            ))
            ->merge($circle->hasSource(CandidateSource::FixedList) ? $circle->userIds : []);

        $users = $this->candidateDirectory->activeUsers($this->distinct($collected), $tenantId);

        return $excludeAbsent ? $this->withoutAbsent($users, $at) : $users;
    }

    /**
     * @return list<string>
     */
    public function resolveUserIds(
        ?CustomRecord $record,
        CandidateCircle $circle,
        string $tenantId,
        CarbonImmutable $at,
        bool $excludeAbsent = true,
    ): array {
        $users = $record === null
            ? $this->resolveWithoutRecord($circle, $tenantId, $at, $excludeAbsent)
            : $this->resolve($record, $circle, $at, $excludeAbsent);

        return array_values($users
            ->map(static fn (User $user): string => (string) $user->getKey())
            ->all());
    }

    public function countWithoutRecord(CandidateCircle $circle, string $tenantId): ?int
    {
        if ($this->dependsOnRecord($circle)) {
            return null;
        }

        return $this->resolveWithoutRecord($circle, $tenantId, CarbonImmutable::now(), false)->count();
    }

    public function dependsOnRecord(CandidateCircle $circle): bool
    {
        return $circle->dependsOnRecord();
    }

    /**
     * @param  Collection<int, string>  $collected
     * @return list<string>
     */
    private function distinct(Collection $collected): array
    {
        return array_values(
            $collected->filter(static fn (string $id): bool => $id !== '')->unique()->all()
        );
    }

    /**
     * @return list<string>
     */
    private function roleUserIds(CandidateCircle $circle, string $tenantId, ?string $teamId): array
    {
        if (!$circle->hasSource(CandidateSource::Role)) {
            return [];
        }

        return $this->candidateDirectory->roleHolderIds($circle->roleIds, $tenantId, $teamId);
    }

    /**
     * @return list<string>
     */
    private function teamIdsFor(CandidateCircle $circle, CustomRecord $record): array
    {
        $teamIds = [];

        if ($circle->hasSource(CandidateSource::Team)) {
            $teamIds = $circle->teamIds;

            if ($circle->includeRecordTeam && $record->team_id !== null) {
                $teamIds[] = $record->team_id;
            }
        }

        if ($circle->hasSource(CandidateSource::Field)
            && $circle->fieldKey !== null
            && SystemFilterField::tryFrom($circle->fieldKey) === SystemFilterField::Team
            && $record->team_id !== null
        ) {
            $teamIds[] = $record->team_id;
        }

        return array_values(array_unique($teamIds));
    }

    /**
     * @return list<string>
     */
    private function fieldUserIds(CandidateCircle $circle, CustomRecord $record): array
    {
        if (!$circle->hasSource(CandidateSource::Field) || $circle->fieldKey === null) {
            return [];
        }

        return match (SystemFilterField::tryFrom($circle->fieldKey)) {
            SystemFilterField::Owner => $record->owner_id === null ? [] : [$record->owner_id],
            SystemFilterField::Team => [],
            default => IdentifierList::from($record->data[$circle->fieldKey] ?? null),
        };
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function withoutAbsent(Collection $users, CarbonImmutable $at): Collection
    {
        $absentIds = $this->absenceDelegationResolver->absentUserIds(
            array_values($users->map(static fn (User $user): string => (string) $user->getKey())->all()),
            $at,
        );

        return $users
            ->reject(static fn (User $user): bool => in_array((string) $user->getKey(), $absentIds, true))
            ->values();
    }
}
