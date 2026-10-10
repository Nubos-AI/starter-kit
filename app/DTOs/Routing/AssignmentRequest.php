<?php

declare(strict_types=1);

namespace App\DTOs\Routing;

use App\DTOs\Governance\CandidateCircle;
use App\Enums\Governance\CandidateSource;
use App\Enums\Routing\AssignmentTarget;
use App\Enums\Routing\ReminderAssignmentScope;
use App\Enums\Routing\RoutingStrategy;
use Carbon\CarbonImmutable;

readonly class AssignmentRequest
{
    public function __construct(
        public string $tenantId,
        public string $automationId,
        public string $nodeId,
        public AssignmentTarget $target,
        public RoutingStrategy $strategy,
        public CandidateCircle $circle,
        public ?string $teamId,
        public ReminderAssignmentScope $reminderScope,
        public ?string $skillId,
        public ?string $skillFieldKey,
        public ?string $fallbackUserId,
        public ?string $fallbackTeamId,
        public CarbonImmutable $at,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config, ActionContext $context, CarbonImmutable $at): self
    {
        $circle = $config['circle'] ?? [];
        $scope = self::text($config, 'reminder_scope');

        return new self(
            tenantId: $context->tenantId,
            automationId: $context->automationId,
            nodeId: $context->nodeId,
            target: AssignmentTarget::from((string) self::text($config, 'target')),
            strategy: RoutingStrategy::from((string) self::text($config, 'strategy')),
            circle: CandidateCircle::fromArray(is_array($circle) ? $circle : []),
            teamId: self::text($config, 'team_id'),
            reminderScope: $scope === null
                ? ReminderAssignmentScope::OpenTasks
                : ReminderAssignmentScope::from($scope),
            skillId: self::text($config, 'skill_id'),
            skillFieldKey: self::text($config, 'skill_field_key'),
            fallbackUserId: self::text($config, 'fallback_user_id'),
            fallbackTeamId: self::text($config, 'fallback_team_id'),
            at: $at,
        );
    }

    public function fallbackCircle(): ?CandidateCircle
    {
        if ($this->fallbackUserId !== null) {
            return new CandidateCircle(
                sources: [CandidateSource::FixedList],
                roleIds: [],
                teamIds: [],
                includeRecordTeam: false,
                fieldKey: null,
                userIds: [$this->fallbackUserId],
            );
        }

        if ($this->fallbackTeamId !== null) {
            return new CandidateCircle(
                sources: [CandidateSource::Team],
                roleIds: [],
                teamIds: [$this->fallbackTeamId],
                includeRecordTeam: false,
                fieldKey: null,
                userIds: [],
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function text(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
