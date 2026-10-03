<?php

declare(strict_types=1);

use App\Enums\Approvals\ApprovalQuorumType;
use App\Models\ApprovalDefinitionStage;
use App\Models\ApprovalProcessStage;
use App\Support\Approvals\ApprovalQuorumEvaluator;
use App\Support\Approvals\ApprovalStageEligibility;
use App\Support\Approvals\ApprovalSubjectSource;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->eligibility = Mockery::mock(ApprovalStageEligibility::class);
    $this->subjects = Mockery::mock(ApprovalSubjectSource::class);

    $this->evaluator = fn (): ApprovalQuorumEvaluator => new ApprovalQuorumEvaluator(
        $this->eligibility,
        $this->subjects,
    );

    /**
     * @param  list<string>  $eligibleIds
     */
    $this->stage = function (
        ApprovalQuorumType $quorumType,
        ?int $quorumCount,
        int $approvals,
        array $eligibleIds = [],
    ): ApprovalProcessStage {
        $definitionStage = ModelStub::make(ApprovalDefinitionStage::class, [
            'id' => ModelStub::ulid('definition-stage'),
            'approval_definition_id' => ModelStub::ulid('definition'),
            'position' => 1,
            'quorum_type' => $quorumType,
            'quorum_count' => $quorumCount,
        ]);

        $stage = ModelStub::make(ApprovalProcessStage::class, [
            'id' => ModelStub::ulid('process-stage'),
            'tenant_id' => $this->tenant->getKey(),
            'approval_process_id' => ModelStub::ulid('process'),
            'approval_definition_stage_id' => $definitionStage->getKey(),
            'position' => 1,
            'attempt' => 1,
        ]);

        $this->subjects->shouldReceive('definitionStage')->with($stage)->andReturn($definitionStage);
        $this->subjects->shouldReceive('approvedDecisionCount')->with($stage)->andReturn($approvals);
        $this->eligibility->shouldReceive('eligibleUserIds')->with($stage)->andReturn($eligibleIds);

        return $stage;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets a single approval settle a stage that asks for any one of the candidates', function (): void {
    $evaluator = ($this->evaluator)();
    $stage = ($this->stage)(ApprovalQuorumType::Any, null, 1, [
        ModelStub::ulid('head'),
        ModelStub::ulid('clerk'),
    ]);

    expect($evaluator->outstanding($stage))->toBe(0)
        ->and($evaluator->isSatisfied($stage))->toBeTrue();
});

it('keeps a stage that asks for any one of the candidates open until the first approval arrives', function (): void {
    $evaluator = ($this->evaluator)();
    $stage = ($this->stage)(ApprovalQuorumType::Any, null, 0, [ModelStub::ulid('head')]);

    expect($evaluator->outstanding($stage))->toBe(1)
        ->and($evaluator->isSatisfied($stage))->toBeFalse();
});

it('counts down towards the demanded number of approvals', function (int $approvals, int $outstanding): void {
    $evaluator = ($this->evaluator)();
    $stage = ($this->stage)(ApprovalQuorumType::AtLeastN, 3, $approvals);

    expect($evaluator->outstanding($stage))->toBe($outstanding)
        ->and($evaluator->isSatisfied($stage))->toBe($outstanding === 0);
})->with([
    'nobody has decided' => [0, 3],
    'one of three' => [1, 2],
    'two of three' => [2, 1],
    'all three' => [3, 0],
    'more than demanded' => [5, 0],
]);

it('demands one approval when a numeric quorum carries no number or an impossible one', function (?int $quorumCount): void {
    $evaluator = ($this->evaluator)();

    expect($evaluator->outstanding(($this->stage)(ApprovalQuorumType::AtLeastN, $quorumCount, 0)))->toBe(1);
})->with([
    'no number at all' => [null],
    'zero' => [0],
    'a negative number' => [-4],
]);

it('demands an approval from every eligible candidate when the stage asks for all of them', function (): void {
    $evaluator = ($this->evaluator)();
    $stage = ($this->stage)(ApprovalQuorumType::All, null, 2, [
        ModelStub::ulid('head'),
        ModelStub::ulid('deputy-head'),
        ModelStub::ulid('clerk'),
    ]);

    expect($evaluator->outstanding($stage))->toBe(1)
        ->and($evaluator->isSatisfied($stage))->toBeFalse();
});

it('settles a stage that asks for all candidates only once every one of them has approved', function (): void {
    $evaluator = ($this->evaluator)();
    $stage = ($this->stage)(ApprovalQuorumType::All, null, 3, [
        ModelStub::ulid('head'),
        ModelStub::ulid('deputy-head'),
        ModelStub::ulid('clerk'),
    ]);

    expect($evaluator->isSatisfied($stage))->toBeTrue();
});

it('still demands one approval when the circle of an all quorum has shrunk to nobody', function (): void {
    $evaluator = ($this->evaluator)();
    $stage = ($this->stage)(ApprovalQuorumType::All, null, 0, []);

    expect($evaluator->outstanding($stage))->toBe(1)
        ->and($evaluator->isSatisfied($stage))->toBeFalse();
});

it('ignores a stored number on an all quorum and follows the eligible circle instead', function (): void {
    $evaluator = ($this->evaluator)();
    $stage = ($this->stage)(ApprovalQuorumType::All, 1, 1, [
        ModelStub::ulid('head'),
        ModelStub::ulid('clerk'),
    ]);

    expect($evaluator->outstanding($stage))->toBe(1);
});
