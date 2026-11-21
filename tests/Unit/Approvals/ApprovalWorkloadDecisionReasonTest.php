<?php

declare(strict_types=1);

use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Support\Approvals\ApprovalExclusionResolver;
use App\Support\Approvals\ApprovalRecordContext;
use App\Support\Approvals\ApprovalStageEligibility;
use App\Support\Approvals\ApprovalWorkloadQuery;
use App\Support\Governance\AbsenceDelegationResolver;
use App\Support\Users\UserOptionPresenter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::user($this->tenant, [], 'workload-approver');

    $this->eligibility = Mockery::mock(ApprovalStageEligibility::class);

    $delegations = Mockery::mock(AbsenceDelegationResolver::class);
    $delegations->shouldReceive('delegatorsFor')->andReturn([]);

    $presenter = Mockery::mock(UserOptionPresenter::class);
    $presenter->shouldReceive('presentMany')->andReturn([]);

    $this->workload = new ApprovalWorkloadQuery(
        $this->eligibility,
        Mockery::mock(ApprovalExclusionResolver::class),
        $delegations,
        new ApprovalRecordContext,
        $presenter,
    );

    $this->stage = ModelStub::make(ApprovalProcessStage::class, [
        'id' => ModelStub::ulid('workload-stage'),
        'tenant_id' => $this->tenant->getKey(),
        'approval_process_id' => ModelStub::ulid('workload-process'),
        'position' => 1,
        'attempt' => 1,
    ]);

    $this->process = ModelStub::make(ApprovalProcess::class, [
        'id' => ModelStub::ulid('workload-process'),
        'tenant_id' => $this->tenant->getKey(),
        'anchor_type' => 'record_transition',
        'anchor_id' => ModelStub::ulid('workload-anchor'),
        'attempt' => 1,
        'current_stage_position' => 1,
    ], ['stages' => new EloquentCollection([$this->stage])]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('tells an approver whose vote on the stage already counts why the decision is closed to them', function (): void {
    $this->eligibility->shouldReceive('mayDecide')->andReturn(false);
    $this->eligibility->shouldReceive('hasDecided')
        ->once()
        ->with($this->stage, (string) $this->user->getKey())
        ->andReturn(true);
    $this->eligibility->shouldNotReceive('holdsDecisionPermission');

    $item = null;

    $shape = QueryShape::attemptedBy(function () use (&$item): void {
        $item = $this->workload->contextFor($this->user, $this->process);
    });

    expect($shape)->toBeNull()
        ->and($item?->canDecide)->toBeFalse()
        ->and($item?->decisionReason)->toBe(__('i18n.backend.support.approvals.approval_workload_query.a_decision_on_this_stage_already_counts_for'))
        ->and($item?->decisionReason)->not->toContain('i18n.backend.');
});
