<?php

declare(strict_types=1);

namespace App\Actions\Routing;

use App\Actions\Engine\UpdateRecordAction;
use App\DTOs\Governance\CandidateCircle;
use App\DTOs\Routing\AssignmentOutcome;
use App\DTOs\Routing\AssignmentRequest;
use App\Enums\Governance\CandidateSource;
use App\Enums\Routing\AssignmentFailureReason;
use App\Models\CustomRecord;
use App\Models\Team;
use App\Models\User;
use App\Support\Governance\CandidateCircleResolver;
use App\Support\Routing\AssignmentSelector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class AssignRecordTeamAction
{
    public function __construct(
        private readonly AssignmentSelector $assignmentSelector,
        private readonly CandidateCircleResolver $candidateCircleResolver,
        private readonly UpdateRecordAction $updateRecordAction,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(CustomRecord $record, AssignmentRequest $request): AssignmentOutcome
    {
        $outcome = $this->assignmentSelector->select(
            $record,
            $request,
            $request->teamId === null ? null : $this->memberIds($request->teamId, $record, $request->at),
            false,
        );

        $candidate = $outcome->user;

        if (!$candidate instanceof User) {
            return $outcome;
        }

        $teamId = ($outcome->usedFallback ? $request->fallbackTeamId : $request->teamId)
            ?? $this->teamIdOf($candidate, $request->tenantId);

        if ($teamId === null) {
            return AssignmentOutcome::failed(AssignmentFailureReason::CandidateWithoutTeam);
        }

        $this->updateRecordAction->execute($record, [
            'version' => $record->version,
            'team_id' => $teamId,
            'owner_id' => (string) $candidate->getKey(),
        ]);

        return $outcome;
    }

    /**
     * @return list<string>
     */
    private function memberIds(string $teamId, CustomRecord $record, CarbonImmutable $at): array
    {
        $members = $this->candidateCircleResolver->resolve(
            $record,
            new CandidateCircle(
                sources: [CandidateSource::Team],
                roleIds: [],
                teamIds: [$teamId],
                includeRecordTeam: false,
                fieldKey: null,
                userIds: [],
            ),
            $at,
            false,
        );

        return array_values(
            $members->map(static fn (User $member): string => (string) $member->getKey())->all(),
        );
    }

    private function teamIdOf(User $candidate, string $tenantId): ?string
    {
        $teamId = Team::query()
            ->withoutGlobalScopes()
            ->where('teams.tenant_id', $tenantId)
            ->whereNull('teams.deleted_at')
            ->whereHas('users', static fn (Builder $query): Builder => $query->whereKey($candidate->getKey()))
            ->orderBy('name')
            ->value('teams.id');

        return is_string($teamId) ? $teamId : null;
    }
}
