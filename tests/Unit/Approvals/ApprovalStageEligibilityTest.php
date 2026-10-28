<?php

declare(strict_types=1);

use App\DTOs\Governance\CandidateCircle;
use App\Enums\Approvals\ApprovalEscalationType;
use App\Enums\Approvals\ApprovalQuorumType;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalDefinitionStage;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\CustomRecord;
use App\Models\PromotionRun;
use App\Support\Approvals\ApprovalExclusionResolver;
use App\Support\Approvals\ApprovalRecordContext;
use App\Support\Approvals\ApprovalStageEligibility;
use App\Support\Approvals\ApprovalSubjectSource;
use App\Support\Governance\AbsenceDelegationResolver;
use App\Support\Governance\CandidateCircleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->anchorPermission = 'promotions.approve';

    $this->head = ModelStub::ulid('head');
    $this->deputyHead = ModelStub::ulid('deputy-head');
    $this->clerk = ModelStub::ulid('clerk');
    $this->outsider = ModelStub::ulid('outsider');
    $this->triggerId = ModelStub::ulid('trigger');

    $this->circles = Mockery::mock(CandidateCircleResolver::class);
    $this->exclusions = Mockery::mock(ApprovalExclusionResolver::class);
    $this->absences = Mockery::mock(AbsenceDelegationResolver::class);
    $this->recordContext = Mockery::mock(ApprovalRecordContext::class);
    $this->subjects = Mockery::mock(ApprovalSubjectSource::class);

    $this->recordContext->shouldReceive('fields')->andReturn(['amount']);

    $this->eligibility = fn (): ApprovalStageEligibility => new ApprovalStageEligibility(
        $this->circles,
        $this->exclusions,
        $this->absences,
        $this->recordContext,
        $this->subjects,
    );

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('approval-record'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('record-owner'),
    ]);

    /**
     * @param  array<string, mixed>  $stageAttributes
     * @param  array<string, mixed>  $definitionStageAttributes
     */
    $this->stage = function (
        array $stageAttributes = [],
        array $definitionStageAttributes = [],
        ?string $anchorType = null,
        ?string $definitionExclusions = null,
    ): ApprovalProcessStage {
        $definition = ModelStub::make(ApprovalDefinition::class, [
            'id' => ModelStub::ulid('definition'),
            'tenant_id' => $this->tenant->getKey(),
            'anchor_type' => $anchorType ?? PromotionRun::class,
            'is_active' => true,
            'exclusions' => $definitionExclusions,
        ]);

        $definitionStage = ModelStub::make(ApprovalDefinitionStage::class, [
            'id' => ModelStub::ulid('definition-stage'),
            'approval_definition_id' => $definition->getKey(),
            'position' => 1,
            'quorum_type' => ApprovalQuorumType::Any,
            'candidate_sources' => ['user_ids' => ['base-circle']],
            'escalation_type' => null,
            'escalation_sources' => null,
            ...$definitionStageAttributes,
        ]);

        $process = ModelStub::make(ApprovalProcess::class, [
            'id' => ModelStub::ulid('process'),
            'tenant_id' => $this->tenant->getKey(),
            'record_id' => $this->record->getKey(),
            'approval_definition_id' => $definition->getKey(),
            'anchor_type' => $anchorType ?? PromotionRun::class,
            'anchor_id' => ModelStub::ulid('anchor'),
            'triggered_by_id' => $this->triggerId,
            'attempt' => 1,
            'current_stage_position' => 1,
        ]);

        $stage = ModelStub::make(ApprovalProcessStage::class, [
            'id' => ModelStub::ulid('process-stage'),
            'tenant_id' => $this->tenant->getKey(),
            'approval_process_id' => $process->getKey(),
            'approval_definition_stage_id' => $definitionStage->getKey(),
            'assigned_user_id' => null,
            'escalation_applied' => false,
            'position' => 1,
            'attempt' => 1,
            'escalation_added_delegations' => null,
            ...$stageAttributes,
        ]);

        $this->subjects->shouldReceive('process')->with($stage)->andReturn($process);
        $this->subjects->shouldReceive('definition')->with($process)->andReturn($definition);
        $this->subjects->shouldReceive('definitionStage')->with($stage)->andReturn($definitionStage);
        $this->subjects->shouldReceive('record')->with($process)->andReturn($this->record);

        return $stage;
    };

    /**
     * @param  list<string>  $present
     * @param  list<string>|null  $full
     */
    $this->circleHolds = function (array $present, ?array $full = null, string $marker = 'base-circle'): void {
        $matchesMarker = static fn (CandidateCircle $circle): bool => $circle->userIds === [$marker];

        $this->circles->shouldReceive('resolveUserIds')
            ->with(Mockery::any(), Mockery::on($matchesMarker), $this->tenant->getKey(), Mockery::type(CarbonImmutable::class), true)
            ->andReturn($present);

        $this->circles->shouldReceive('resolveUserIds')
            ->with(Mockery::any(), Mockery::on($matchesMarker), $this->tenant->getKey(), Mockery::type(CarbonImmutable::class), false)
            ->andReturn($full ?? $present);
    };

    /**
     * @param  list<string>  $holderIds
     */
    $this->permissionHeldBy = function (array $holderIds): void {
        $this->subjects->shouldReceive('permissionHolderIds')
            ->andReturnUsing(static fn (string $tenantId, array $userIds, string $permission): array => array_values(
                array_intersect($userIds, $holderIds),
            ));
    };

    /**
     * @param  list<string>  $excludedIds
     */
    $this->excluded = function (array $excludedIds): void {
        $this->exclusions->shouldReceive('excludedUserIds')->andReturn($excludedIds);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('offers a decision only to the people of the circle who also hold the decision permission of the anchor', function (): void {
    ($this->circleHolds)([$this->head, $this->deputyHead, $this->clerk]);
    ($this->permissionHeldBy)([$this->head, $this->deputyHead]);
    ($this->excluded)([]);

    expect(($this->eligibility)()->eligibleUserIds(($this->stage)()))
        ->toEqualCanonicalizing([$this->head, $this->deputyHead]);
});

it('removes an excluded person from the circle although they hold the decision permission', function (): void {
    ($this->circleHolds)([$this->head, $this->deputyHead]);
    ($this->permissionHeldBy)([$this->head, $this->deputyHead]);
    ($this->excluded)([$this->deputyHead]);

    expect(($this->eligibility)()->eligibleUserIds(($this->stage)()))->toBe([$this->head]);
});

it('widens the circle only once an escalation of the widening kind has really been applied', function (bool $applied, ?ApprovalEscalationType $type, bool $widens): void {
    ($this->circleHolds)([$this->head]);
    ($this->circleHolds)([$this->clerk], null, 'widened-circle');
    ($this->permissionHeldBy)([$this->head, $this->clerk]);
    ($this->excluded)([]);

    $stage = ($this->stage)(
        ['escalation_applied' => $applied],
        ['escalation_type' => $type, 'escalation_sources' => ['user_ids' => ['widened-circle']]],
    );

    expect(($this->eligibility)()->eligibleUserIds($stage))
        ->toEqualCanonicalizing($widens ? [$this->head, $this->clerk] : [$this->head]);
})->with([
    'not escalated at all' => [false, ApprovalEscalationType::WidenCircle, false],
    'escalated by forwarding' => [true, ApprovalEscalationType::Delegate, false],
    'escalated by notifying again' => [true, ApprovalEscalationType::NotifyAgain, false],
    'no escalation kind configured' => [true, null, false],
    'escalated by widening' => [true, ApprovalEscalationType::WidenCircle, true],
]);

it('ignores an empty widening configuration and never asks the circle resolver about it', function (): void {
    ($this->circleHolds)([$this->head]);
    ($this->permissionHeldBy)([$this->head]);
    ($this->excluded)([]);

    $stage = ($this->stage)(
        ['escalation_applied' => true],
        ['escalation_type' => ApprovalEscalationType::WidenCircle, 'escalation_sources' => []],
    );

    expect(($this->eligibility)()->eligibleUserIds($stage))->toBe([$this->head]);
});

it('narrows the eligible circle to the assigned person alone', function (): void {
    ($this->circleHolds)([$this->head, $this->deputyHead, $this->clerk]);
    ($this->permissionHeldBy)([$this->head, $this->deputyHead, $this->clerk]);
    ($this->excluded)([]);

    expect(($this->eligibility)()->eligibleUserIds(($this->stage)(['assigned_user_id' => $this->clerk])))
        ->toBe([$this->clerk]);
});

it('keeps a frozen escalation delegation eligible although the deputy never belonged to the circle', function (): void {
    ($this->circleHolds)([$this->head]);
    ($this->permissionHeldBy)([$this->head, $this->deputyHead]);
    ($this->excluded)([]);

    $stage = ($this->stage)(['escalation_added_delegations' => [$this->deputyHead => $this->head]]);
    $eligibility = ($this->eligibility)();

    expect($eligibility->eligibleUserIds($stage))->toEqualCanonicalizing([$this->head, $this->deputyHead])
        ->and($eligibility->escalationDelegatorFor($this->deputyHead, $stage))->toBe($this->head)
        ->and($eligibility->escalationDelegatorFor($this->head, $stage))->toBeNull()
        ->and($eligibility->escalationDelegatorFor($this->clerk, $stage))->toBeNull();
});

it('drops a frozen escalation delegation whose deputy does not hold the decision permission', function (): void {
    ($this->circleHolds)([$this->head]);
    ($this->permissionHeldBy)([$this->head]);
    ($this->excluded)([]);

    $stage = ($this->stage)(['escalation_added_delegations' => [$this->deputyHead => $this->head]]);

    expect(($this->eligibility)()->eligibleUserIds($stage))->toBe([$this->head])
        ->and(($this->eligibility)()->escalationDelegatorFor($this->deputyHead, $stage))->toBeNull();
});

it('refuses a decision from someone outside the eligible circle', function (): void {
    ($this->circleHolds)([$this->head]);
    ($this->permissionHeldBy)([$this->head, $this->outsider]);
    ($this->excluded)([]);

    $stage = ($this->stage)();

    expect(($this->eligibility)()->mayDecide($this->outsider, $stage, null))->toBeFalse()
        ->and(($this->eligibility)()->mayDecide($this->head, $stage, null))->toBeTrue();
});

it('refuses a representation of oneself and never asks the absence register about it', function (): void {
    ($this->circleHolds)([$this->head]);
    ($this->permissionHeldBy)([$this->head]);
    ($this->excluded)([]);
    $this->absences->shouldNotReceive('delegateFor');

    expect(($this->eligibility)()->mayDecide($this->head, ($this->stage)(), $this->head))->toBeFalse();
});

it('refuses a representation of someone who is not eligible at all', function (): void {
    ($this->circleHolds)([$this->head]);
    ($this->permissionHeldBy)([$this->head, $this->deputyHead]);
    ($this->excluded)([]);
    $this->absences->shouldNotReceive('delegateFor');

    expect(($this->eligibility)()->mayDecide($this->deputyHead, ($this->stage)(), $this->outsider))->toBeFalse();
});

it('refuses a representation when the absence register names another deputy', function (): void {
    ($this->circleHolds)([$this->head]);
    ($this->permissionHeldBy)([$this->head, $this->deputyHead, $this->outsider]);
    ($this->excluded)([]);
    $this->absences->shouldReceive('delegateFor')->with($this->head, Mockery::type(CarbonImmutable::class))->andReturn($this->outsider);

    expect(($this->eligibility)()->mayDecide($this->deputyHead, ($this->stage)(), $this->head))->toBeFalse();
});

it('refuses a representation by a deputy who does not hold the decision permission', function (): void {
    ($this->circleHolds)([$this->head]);
    ($this->permissionHeldBy)([$this->head]);
    ($this->excluded)([]);
    $this->absences->shouldReceive('delegateFor')->with($this->head, Mockery::type(CarbonImmutable::class))->andReturn($this->deputyHead);
    $this->exclusions->shouldNotReceive('isDelegateBlocked');

    expect(($this->eligibility)()->mayDecide($this->deputyHead, ($this->stage)(), $this->head))->toBeFalse();
});

it('refuses a representation the exclusion rules block for the deputy', function (): void {
    ($this->circleHolds)([$this->head]);
    ($this->permissionHeldBy)([$this->head, $this->deputyHead]);
    ($this->excluded)([]);
    $this->absences->shouldReceive('delegateFor')->with($this->head, Mockery::type(CarbonImmutable::class))->andReturn($this->deputyHead);
    $this->exclusions->shouldReceive('isDelegateBlocked')->andReturn(true);

    expect(($this->eligibility)()->mayDecide($this->deputyHead, ($this->stage)(), $this->head))->toBeFalse();
});

it('allows a representation only when eligibility, absence register, permission and exclusions all agree', function (): void {
    ($this->circleHolds)([$this->head]);
    ($this->permissionHeldBy)([$this->head, $this->deputyHead]);
    ($this->excluded)([]);
    $this->absences->shouldReceive('delegateFor')->with($this->head, Mockery::type(CarbonImmutable::class))->andReturn($this->deputyHead);
    $this->exclusions->shouldReceive('isDelegateBlocked')->andReturn(false);

    expect(($this->eligibility)()->mayDecide($this->deputyHead, ($this->stage)(), $this->head))->toBeTrue();
});

it('writes to the present candidates and to the deputies of the absent ones', function (): void {
    ($this->circleHolds)([$this->head], [$this->head, $this->clerk]);
    ($this->permissionHeldBy)([$this->head, $this->clerk, $this->deputyHead]);
    ($this->excluded)([]);
    $this->absences->shouldReceive('delegateFor')->with($this->clerk, Mockery::type(CarbonImmutable::class))->andReturn($this->deputyHead);
    $this->exclusions->shouldReceive('isDelegateBlocked')->andReturn(false);

    expect(($this->eligibility)()->notificationRecipientIds(($this->stage)()))
        ->toEqualCanonicalizing([$this->head, $this->deputyHead]);
});

it('writes only to the deputy when the stage is assigned to an absent person', function (): void {
    ($this->circleHolds)([$this->head], [$this->head, $this->clerk]);
    ($this->permissionHeldBy)([$this->head, $this->clerk, $this->deputyHead]);
    ($this->excluded)([]);
    $this->absences->shouldReceive('delegateFor')->with($this->clerk, Mockery::type(CarbonImmutable::class))->andReturn($this->deputyHead);
    $this->exclusions->shouldReceive('isDelegateBlocked')->andReturn(false);

    expect(($this->eligibility)()->notificationRecipientIds(($this->stage)(['assigned_user_id' => $this->clerk])))
        ->toBe([$this->deputyHead]);
});

it('leaves a deputy the exclusion rules block out of the recipients', function (): void {
    ($this->circleHolds)([$this->head], [$this->head, $this->clerk]);
    ($this->permissionHeldBy)([$this->head, $this->clerk, $this->deputyHead]);
    ($this->excluded)([]);
    $this->absences->shouldReceive('delegateFor')->with($this->clerk, Mockery::type(CarbonImmutable::class))->andReturn($this->deputyHead);
    $this->exclusions->shouldReceive('isDelegateBlocked')->andReturn(true);

    expect(($this->eligibility)()->notificationRecipientIds(($this->stage)()))->toBe([$this->head]);
});

it('asks nobody about a permission the anchor does not demand and lets every candidate through', function (): void {
    Config::set('engine.approvals.decision_permissions', []);

    ($this->circleHolds)([$this->head, $this->outsider]);
    ($this->excluded)([]);
    $this->subjects->shouldNotReceive('permissionHolderIds');

    expect(($this->eligibility)()->eligibleUserIds(($this->stage)()))
        ->toEqualCanonicalizing([$this->head, $this->outsider]);
});

it('keeps the tenant of the process when it asks who holds the decision permission', function (): void {
    $askedWith = [];

    $this->subjects->shouldReceive('permissionHolderIds')
        ->andReturnUsing(function (string $tenantId, array $userIds, string $permission) use (&$askedWith): array {
            $askedWith[] = [$tenantId, $permission];

            return $userIds;
        });

    ($this->circleHolds)([$this->head]);
    ($this->excluded)([]);

    ($this->eligibility)()->eligibleUserIds(($this->stage)());

    expect($askedWith)->not->toBeEmpty()
        ->and(array_unique(array_column($askedWith, 0)))->toBe([$this->tenant->getKey()])
        ->and(array_unique(array_column($askedWith, 1)))->toBe([$this->anchorPermission]);
});

it('forces the trigger exclusion for a configured anchor and leaves every other anchor with its own set', function (): void {
    Config::set('engine.approvals.trigger_forced_anchors', [PromotionRun::class]);

    $lenient = ModelStub::make(ApprovalDefinition::class, [
        'id' => ModelStub::ulid('lenient-definition'),
        'tenant_id' => $this->tenant->getKey(),
        'exclusions' => (string) json_encode(['trigger' => false, 'last_editor' => false, 'creator' => false, 'owner' => false]),
    ]);

    $forced = ($this->eligibility)()->exclusionsFor($lenient, PromotionRun::class);
    $untouched = ($this->eligibility)()->exclusionsFor($lenient, CustomRecord::class);

    expect($forced->trigger)->toBeTrue()
        ->and($forced->lastEditor)->toBeFalse()
        ->and($forced->creator)->toBeFalse()
        ->and($forced->owner)->toBeFalse()
        ->and($untouched->trigger)->toBeFalse();
});
