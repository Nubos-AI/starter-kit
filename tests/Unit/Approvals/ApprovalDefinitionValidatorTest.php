<?php

declare(strict_types=1);

use App\DTOs\Approvals\ApprovalExclusionSet;
use App\Enums\Approvals\ApprovalEscalationType;
use App\Enums\Approvals\ApprovalQuorumType;
use App\Enums\Engine\SystemFilterField;
use App\Enums\Governance\CandidateSource;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalDefinitionStage;
use App\Support\Approvals\ApprovalDefinitionValidator;
use App\Support\Approvals\ApprovalStageEligibility;
use App\Support\Governance\CandidateCircleResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->circles = Mockery::mock(CandidateCircleResolver::class);
    $this->eligibility = Mockery::mock(ApprovalStageEligibility::class);

    $this->validator = fn (): ApprovalDefinitionValidator => new ApprovalDefinitionValidator($this->circles, $this->eligibility);

    $this->roleCircle = ['sources' => [CandidateSource::Role->value], 'role_ids' => [ModelStub::ulid('role')]];

    $this->recordFieldCircle = [
        'sources' => [CandidateSource::Field->value],
        'field_key' => SystemFilterField::Owner->value,
    ];

    $this->recordTeamCircle = [
        'sources' => [CandidateSource::Team->value],
        'include_record_team' => true,
    ];

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    $this->stage = function (array $overrides = []): array {
        return ['candidate_sources' => $this->roleCircle, ...$overrides];
    };

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return array<string, string>
     */
    $this->errorsOf = function (array $stages): array {
        try {
            ($this->validator)()->assertAnchorConfiguration(['stages' => $stages]);
        } catch (ValidationException $exception) {
            return array_map(
                static fn (array $messages): string => (string) $messages[0],
                $exception->errors(),
            );
        }

        $this->fail('the validator accepted a configuration it should have refused');
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('accepts a stage whose circle draws on a role alone', function (): void {
    expect(fn () => ($this->validator)()->assertAnchorConfiguration(['stages' => [($this->stage)()]]))
        ->not->toThrow(ValidationException::class);
});

it('refuses a configuration without any stage', function (): void {
    expect(($this->errorsOf)([]))->toHaveKey('stages');
});

it('refuses a first stage whose circle draws on a record field and names that stage', function (): void {
    $errors = ($this->errorsOf)([($this->stage)(['candidate_sources' => $this->recordFieldCircle])]);

    expect($errors)->toHaveKey('stages.0.candidate_sources')
        ->and($errors['stages.0.candidate_sources'])->toContain('1');
});

it('refuses a stage whose team source includes the team of the record', function (): void {
    expect(($this->errorsOf)([($this->stage)(['candidate_sources' => $this->recordTeamCircle])]))
        ->toHaveKey('stages.0.candidate_sources');
});

it('names the offending follow up stage by its one based position', function (): void {
    $errors = ($this->errorsOf)([
        ($this->stage)(),
        ($this->stage)(['candidate_sources' => $this->recordFieldCircle]),
    ]);

    expect($errors)->toHaveKey('stages.1.candidate_sources')
        ->and($errors['stages.1.candidate_sources'])->toContain('2');
});

it('refuses a stage whose widened escalation circle draws on a record field', function (): void {
    expect(($this->errorsOf)([($this->stage)([
        'escalation_type' => ApprovalEscalationType::WidenCircle->value,
        'escalation_sources' => $this->recordFieldCircle,
    ])]))->toHaveKey('stages.0.escalation_sources');
});

it('refuses a record bound circle even when it carries enough approvers for its quorum', function (): void {
    $this->circles->shouldReceive('countWithoutRecord')->andReturn(9);

    expect(($this->errorsOf)([($this->stage)([
        'candidate_sources' => $this->recordFieldCircle,
        'quorum_type' => ApprovalQuorumType::AtLeastN->value,
        'quorum_count' => 2,
    ])]))->toHaveKey('stages.0.candidate_sources');
});

it('refuses a minimum count quorum below two', function (): void {
    expect(($this->errorsOf)([($this->stage)([
        'quorum_type' => ApprovalQuorumType::AtLeastN->value,
        'quorum_count' => 1,
    ])]))->toHaveKey('stages.0.quorum_count');
});

it('refuses a count on a quorum type that carries none', function (): void {
    expect(($this->errorsOf)([($this->stage)([
        'quorum_type' => ApprovalQuorumType::All->value,
        'quorum_count' => 2,
    ])]))->toHaveKey('stages.0.quorum_count');
});

it('refuses a minimum count quorum the circle can never reach', function (): void {
    $this->circles->shouldReceive('countWithoutRecord')->once()->andReturn(2);

    $errors = ($this->errorsOf)([($this->stage)([
        'quorum_type' => ApprovalQuorumType::AtLeastN->value,
        'quorum_count' => 3,
    ])]);

    expect($errors['stages.0.quorum_count'])->toContain('3')
        ->and($errors['stages.0.quorum_count'])->toContain('2');
});

it('accepts a minimum count quorum the circle can reach and one it cannot count', function (): void {
    $this->circles->shouldReceive('countWithoutRecord')->twice()->andReturn(5, null);

    expect(fn () => ($this->validator)()->assertAnchorConfiguration(['stages' => [
        ($this->stage)(['quorum_type' => ApprovalQuorumType::AtLeastN->value, 'quorum_count' => 3]),
        ($this->stage)(['quorum_type' => ApprovalQuorumType::AtLeastN->value, 'quorum_count' => 9]),
    ]]))->not->toThrow(ValidationException::class);
});

it('refuses a deadline that is not a whole positive hour count', function (mixed $deadline): void {
    expect(($this->errorsOf)([($this->stage)(['deadline_hours' => $deadline])]))
        ->toHaveKey('stages.0.deadline_hours');
})->with([
    'zero' => 0,
    'negative' => -1,
    'fractional' => 1.5,
    'string' => '4',
]);

it('refuses a fallback pool on an escalation that does not widen the circle', function (): void {
    expect(($this->errorsOf)([($this->stage)([
        'escalation_type' => ApprovalEscalationType::NotifyAgain->value,
        'escalation_sources' => $this->roleCircle,
    ])]))->toHaveKey('stages.0.escalation_sources');
});

it('refuses a widening escalation without any fallback pool', function (): void {
    expect(($this->errorsOf)([($this->stage)([
        'escalation_type' => ApprovalEscalationType::WidenCircle->value,
    ])]))->toHaveKey('stages.0.escalation_sources');
});

it('refuses a stage without any pool of potential approvers', function (): void {
    expect(($this->errorsOf)([['quorum_type' => ApprovalQuorumType::All->value]]))
        ->toHaveKey('stages.0.candidate_sources');
});

it('refuses a pool whose named sources carry nothing at all', function (): void {
    expect(($this->errorsOf)([($this->stage)([
        'candidate_sources' => ['sources' => [CandidateSource::Role->value], 'role_ids' => []],
    ])]))->toHaveKey('stages.0.candidate_sources');
});

it('refuses a person field other than the owner as a pool', function (): void {
    expect(($this->errorsOf)([($this->stage)([
        'candidate_sources' => ['sources' => [CandidateSource::Field->value], 'field_key' => 'approver'],
    ])]))->toHaveKey('stages.0.candidate_sources');
});

it('reports no dead end stage when neither the trigger nor the owner is excluded', function (): void {
    $this->eligibility->shouldReceive('exclusionsFor')->once()->andReturn(
        new ApprovalExclusionSet(trigger: false, lastEditor: true, creator: true, owner: false),
    );

    $definition = ModelStub::make(ApprovalDefinition::class, [
        'id' => ModelStub::ulid('definition'),
        'tenant_id' => $this->tenant->getKey(),
        'anchor_type' => 'App\\Models\\PromotionRun',
    ], [
        'stages' => new EloquentCollection([
            ModelStub::make(ApprovalDefinitionStage::class, [
                'id' => ModelStub::ulid('stage-one'),
                'position' => 1,
                'candidate_sources' => ['sources' => [CandidateSource::FixedList->value], 'user_ids' => [ModelStub::ulid('one')]],
            ]),
        ]),
    ]);

    expect(($this->validator)()->detectDeadEndStagePositions($definition))->toBe([]);
});

it('reports a single person fixed list stage as a dead end once the trigger is excluded', function (): void {
    $this->eligibility->shouldReceive('exclusionsFor')->andReturn(ApprovalExclusionSet::strict());

    $definition = ModelStub::make(ApprovalDefinition::class, [
        'id' => ModelStub::ulid('definition'),
        'tenant_id' => $this->tenant->getKey(),
        'anchor_type' => 'App\\Models\\PromotionRun',
    ], [
        'stages' => new EloquentCollection([
            ModelStub::make(ApprovalDefinitionStage::class, [
                'id' => ModelStub::ulid('stage-two'),
                'position' => 2,
                'candidate_sources' => ['sources' => [CandidateSource::FixedList->value], 'user_ids' => [ModelStub::ulid('one')]],
            ]),
            ModelStub::make(ApprovalDefinitionStage::class, [
                'id' => ModelStub::ulid('stage-one'),
                'position' => 1,
                'candidate_sources' => ['sources' => [CandidateSource::Role->value], 'role_ids' => [ModelStub::ulid('role')]],
            ]),
        ]),
    ]);

    expect(($this->validator)()->detectDeadEndStagePositions($definition))->toBe([2])
        ->and(($this->validator)()->deadEndStageWarnings($definition, 'die Promotion'))
        ->toHaveCount(1);
});

it('reports no dead end for a role circle even when the trigger is excluded', function (): void {
    $this->eligibility->shouldReceive('exclusionsFor')->andReturn(ApprovalExclusionSet::strict());

    $definition = ModelStub::make(ApprovalDefinition::class, [
        'id' => ModelStub::ulid('definition'),
        'tenant_id' => $this->tenant->getKey(),
        'anchor_type' => 'App\\Models\\PromotionRun',
    ], [
        'stages' => new EloquentCollection([
            ModelStub::make(ApprovalDefinitionStage::class, [
                'id' => ModelStub::ulid('stage-one'),
                'position' => 1,
                'candidate_sources' => ['sources' => [CandidateSource::Role->value], 'role_ids' => [ModelStub::ulid('role')]],
            ]),
        ]),
    ]);

    expect(($this->validator)()->detectDeadEndStagePositions($definition))->toBe([]);
});

it('names the subject it was handed in its dead end warning', function (): void {
    $this->eligibility->shouldReceive('exclusionsFor')->andReturn(ApprovalExclusionSet::strict());

    $definition = ModelStub::make(ApprovalDefinition::class, [
        'id' => ModelStub::ulid('definition'),
        'tenant_id' => $this->tenant->getKey(),
        'anchor_type' => 'App\\Models\\PromotionRun',
    ], [
        'stages' => new EloquentCollection([
            ModelStub::make(ApprovalDefinitionStage::class, [
                'id' => ModelStub::ulid('stage-one'),
                'position' => 1,
                'candidate_sources' => ['sources' => [CandidateSource::FixedList->value], 'user_ids' => [ModelStub::ulid('one')]],
            ]),
        ]),
    ]);

    expect(($this->validator)()->deadEndStageWarnings($definition, 'die Promotion')[0])
        ->toContain('die Promotion');
});
