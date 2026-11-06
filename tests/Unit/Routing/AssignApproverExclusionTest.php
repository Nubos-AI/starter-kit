<?php

declare(strict_types=1);

use App\Actions\Routing\AssignApprovalStageAction;
use App\DTOs\Approvals\ApprovalExclusionSet;
use App\DTOs\Routing\ActionContext;
use App\DTOs\Routing\AssignmentRequest;
use App\DTOs\Routing\RoutingContext;
use App\Enums\Approvals\ApprovalExclusion;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Enums\Governance\CandidateSource;
use App\Enums\Routing\AssignmentFailureReason;
use App\Enums\Routing\AssignmentTarget;
use App\Enums\Routing\RoutingStrategy;
use App\Models\ApprovalDefinition;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Approvals\ApprovalExclusionResolver;
use App\Support\Approvals\ApprovalStageEligibility;
use App\Support\Governance\FieldEditorResolver;
use App\Support\Routing\AssignmentSelector;
use App\Support\Routing\RoutingCandidateResolver;
use Carbon\CarbonImmutable;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->ownerId = ModelStub::ulid('owner');
    $this->triggerId = ModelStub::ulid('trigger');
    $this->creatorId = ModelStub::ulid('creator');
    $this->lastEditorId = ModelStub::ulid('last-editor');
    $this->neutralId = ModelStub::ulid('neutral');

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('object-type'),
        'owner_id' => $this->ownerId,
        'version' => 1,
    ]);

    $this->resolver = fn (): ApprovalExclusionResolver => new ApprovalExclusionResolver(
        new class($this->creatorId, $this->lastEditorId) extends FieldEditorResolver
        {
            public function __construct(private readonly string $creatorId, private readonly string $lastEditorId) {}

            /**
             * @param  list<string>  $fieldKeys
             */
            public function lastEditorOfFields(CustomRecord $record, array $fieldKeys): ?string
            {
                return $fieldKeys === [] ? null : $this->lastEditorId;
            }

            public function creatorOf(CustomRecord $record): ?string
            {
                return $this->creatorId;
            }
        },
    );

    $this->exclusions = static fn (bool $trigger, bool $lastEditor, bool $creator, bool $owner): ApprovalExclusionSet => new ApprovalExclusionSet(
        trigger: $trigger,
        lastEditor: $lastEditor,
        creator: $creator,
        owner: $owner,
    );

    $this->selectorFor = static function (array $users): array {
        $candidates = new class($users) extends RoutingCandidateResolver
        {
            /**
             * @var list<list<string>|null>
             */
            public array $seen = [];

            /**
             * @param  array<string, User>  $users
             */
            public function __construct(private readonly array $users) {}

            public function select(CustomRecord $record, RoutingContext $context): ?User
            {
                $this->seen[] = $context->restrictToUserIds;

                foreach ($context->circle->userIds as $userId) {
                    if (!isset($this->users[$userId])) {
                        continue;
                    }

                    if ($context->restrictToUserIds !== null && !in_array($userId, $context->restrictToUserIds, true)) {
                        continue;
                    }

                    return $this->users[$userId];
                }

                return null;
            }
        };

        return [new AssignmentSelector($candidates), $candidates];
    };

    $this->requestWith = fn (array $config): AssignmentRequest => AssignmentRequest::fromConfig(
        [
            'target' => AssignmentTarget::ApprovalStage->value,
            'strategy' => RoutingStrategy::RoundRobin->value,
            'circle' => [
                'sources' => [CandidateSource::FixedList->value],
                'role_ids' => [],
                'team_ids' => [],
                'include_record_team' => false,
                'field_key' => null,
                'user_ids' => [$this->neutralId],
            ],
            ...$config,
        ],
        new ActionContext(
            automationId: ModelStub::ulid('automation'),
            versionId: ModelStub::ulid('version'),
            runId: ModelStub::ulid('run'),
            nodeId: 'assign-node',
            recordId: (string) $this->record->getKey(),
            objectTypeId: ModelStub::ulid('object-type'),
            actorId: $this->triggerId,
            config: [],
            tenantId: (string) $this->tenant->getKey(),
        ),
        CarbonImmutable::now(),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('keeps the record owner out of the circle while the owner exclusion is on', function (): void {
    $excluded = ($this->resolver)()->excludedUserIds(
        $this->record,
        ($this->exclusions)(false, false, false, true),
        null,
        [],
    );

    expect($excluded)->toBe([$this->ownerId]);
});

it('lets the record owner stay in the circle while the owner exclusion is off', function (): void {
    $excluded = ($this->resolver)()->excludedUserIds(
        $this->record,
        ($this->exclusions)(false, false, false, false),
        null,
        [],
    );

    expect($excluded)->toBe([]);
});

it('keeps whoever triggered the approval out of the circle', function (): void {
    $resolver = ($this->resolver)();

    expect($resolver->excludedUserIds($this->record, ($this->exclusions)(true, false, false, false), $this->triggerId, []))
        ->toBe([$this->triggerId])
        ->and($resolver->reasonsFor($this->triggerId, $this->record, ($this->exclusions)(true, false, false, false), $this->triggerId, []))
        ->toBe([ApprovalExclusion::Trigger]);
});

it('keeps the creator and the last editor of the gated fields out of the circle', function (): void {
    $resolver = ($this->resolver)();
    $enabled = ($this->exclusions)(false, true, true, false);

    $excluded = $resolver->excludedUserIds($this->record, $enabled, null, ['amount']);

    sort($excluded);
    $expected = [$this->creatorId, $this->lastEditorId];
    sort($expected);

    expect($excluded)->toBe($expected)
        ->and($resolver->reasonsFor($this->creatorId, $this->record, $enabled, null, ['amount']))
        ->toBe([ApprovalExclusion::Creator])
        ->and($resolver->reasonsFor($this->lastEditorId, $this->record, $enabled, null, ['amount']))
        ->toBe([ApprovalExclusion::LastEditor]);
});

it('leaves the last editor in the circle while no gated field is named', function (): void {
    $excluded = ($this->resolver)()->excludedUserIds(
        $this->record,
        ($this->exclusions)(false, true, false, false),
        null,
        [],
    );

    expect($excluded)->toBe([]);
});

it('blocks a delegate whose principal is excluded and a delegate who is excluded themselves', function (): void {
    $resolver = ($this->resolver)();
    $enabled = ($this->exclusions)(true, false, false, true);

    expect($resolver->isDelegateBlocked($this->neutralId, $this->ownerId, $this->record, $enabled, $this->triggerId, []))
        ->toBeTrue()
        ->and($resolver->isDelegateBlocked($this->triggerId, $this->neutralId, $this->record, $enabled, $this->triggerId, []))
        ->toBeTrue()
        ->and($resolver->isDelegateBlocked($this->neutralId, $this->creatorId, $this->record, $enabled, $this->triggerId, []))
        ->toBeFalse();
});

it('forces the trigger exclusion on an anchor type the configuration marks as trigger forced', function (): void {
    config(['engine.approvals.trigger_forced_anchors' => ['App\\Models\\PromotionRun']]);

    $definition = ModelStub::make(ApprovalDefinition::class, [
        'id' => ModelStub::ulid('definition'),
        'tenant_id' => $this->tenant->getKey(),
        'exclusions' => (string) json_encode(['trigger' => false, 'last_editor' => false, 'creator' => false, 'owner' => false]),
    ]);

    $eligibility = app(ApprovalStageEligibility::class);

    expect($eligibility->exclusionsFor($definition, 'App\\Models\\PromotionRun')->trigger)->toBeTrue()
        ->and($eligibility->exclusionsFor($definition, 'App\\Models\\SomethingElse')->trigger)->toBeFalse();
});

it('hands the eligible approvers down as the restriction of the selection', function (): void {
    $candidate = ModelStub::make(User::class, ['id' => $this->neutralId, 'tenant_id' => $this->tenant->getKey()]);
    [$selector, $candidates] = ($this->selectorFor)([$this->neutralId => $candidate]);

    $outcome = $selector->select($this->record, ($this->requestWith)([]), [$this->neutralId]);

    expect($outcome->isAssigned())->toBeTrue()
        ->and($outcome->user?->getKey())->toBe($this->neutralId)
        ->and($candidates->seen)->toBe([[$this->neutralId]]);
});

it('refuses a fallback target that the eligible set does not carry', function (): void {
    $excludedCandidate = ModelStub::make(User::class, ['id' => $this->ownerId, 'tenant_id' => $this->tenant->getKey()]);
    [$selector, $candidates] = ($this->selectorFor)([$this->ownerId => $excludedCandidate]);

    $outcome = $selector->select(
        $this->record,
        ($this->requestWith)([
            'circle' => ['sources' => [CandidateSource::FixedList->value], 'user_ids' => [$this->ownerId]],
            'fallback_user_id' => $this->ownerId,
        ]),
        [$this->neutralId],
    );

    expect($outcome->isAssigned())->toBeFalse()
        ->and($outcome->reason)->toBe(AssignmentFailureReason::NoCandidate)
        ->and($candidates->seen)->toBe([[$this->neutralId], [$this->neutralId]]);
});

it('lets a fallback target through once the eligible set carries it', function (): void {
    $candidate = ModelStub::make(User::class, ['id' => $this->neutralId, 'tenant_id' => $this->tenant->getKey()]);
    [$selector] = ($this->selectorFor)([$this->neutralId => $candidate]);

    $outcome = $selector->select(
        $this->record,
        ($this->requestWith)([
            'circle' => ['sources' => [CandidateSource::FixedList->value], 'user_ids' => [$this->ownerId]],
            'fallback_user_id' => $this->neutralId,
        ]),
        [$this->neutralId],
    );

    expect($outcome->isAssigned())->toBeTrue()
        ->and($outcome->usedFallback)->toBeTrue();
});

it('looks for an open approval process of that record only', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => app(AssignApprovalStageAction::class)
        ->execute($this->record, ($this->requestWith)([])));

    expect($shape)->not->toBeNull()
        ->and($shape?->targets('approval_processes'))->toBeTrue()
        ->and($shape?->hasBinding((string) $this->record->getKey()))->toBeTrue()
        ->and($shape?->hasBinding(ApprovalProcessStatus::Pending->value))->toBeTrue()
        ->and($shape?->sql)->toContain('order by "started_at" desc');
});
