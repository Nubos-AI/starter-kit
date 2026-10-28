<?php

declare(strict_types=1);

use App\Enums\Approvals\ApprovalProcessStatus;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\Tenant;
use App\Policies\Approvals\ApprovalProcessPolicy;
use App\Support\Approvals\ApprovalStageEligibility;
use App\Support\Approvals\ApprovalSubjectSource;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->foreignTenantId = ModelStub::ulid('foreign-tenant');

    $this->triggerId = ModelStub::ulid('trigger');

    $this->eligibility = Mockery::mock(ApprovalStageEligibility::class);
    $this->subjects = Mockery::mock(ApprovalSubjectSource::class);

    $this->policy = fn (): ApprovalProcessPolicy => new ApprovalProcessPolicy($this->eligibility, $this->subjects);

    $this->openStage = ModelStub::make(ApprovalProcessStage::class, [
        'id' => ModelStub::ulid('process-stage'),
        'tenant_id' => $this->tenant->getKey(),
        'approval_process_id' => ModelStub::ulid('process'),
        'approval_definition_stage_id' => ModelStub::ulid('definition-stage'),
        'position' => 1,
        'attempt' => 1,
    ]);

    /**
     * @param  array<string, mixed>  $attributes
     */
    $this->process = function (array $attributes = []): ApprovalProcess {
        return ModelStub::make(ApprovalProcess::class, [
            'id' => ModelStub::ulid('process'),
            'tenant_id' => $this->tenant->getKey(),
            'record_id' => ModelStub::ulid('approval-record'),
            'approval_definition_id' => ModelStub::ulid('definition'),
            'anchor_type' => 'Nubos\\Pipelines\\Models\\StageTransition',
            'anchor_id' => ModelStub::ulid('anchor'),
            'triggered_by_id' => $this->triggerId,
            'status' => ApprovalProcessStatus::Pending,
            'attempt' => 1,
            'current_stage_position' => 1,
            ...$attributes,
        ]);
    };

    $this->stageOf = function (?ApprovalProcessStage $stage): void {
        $this->subjects->shouldReceive('currentStage')->andReturn($stage);
    };

    $this->user = fn (?string $tenantId = null, string $seed = 'viewer') => AccessContext::user(
        $tenantId === null ? $this->tenant : ModelStub::make(Tenant::class, ['id' => $tenantId]),
        [],
        $seed,
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a decision on an approval of another tenant and never asks who is eligible', function (): void {
    $this->eligibility->shouldNotReceive('mayDecide');
    $this->subjects->shouldNotReceive('currentStage');

    $foreign = ($this->user)($this->foreignTenantId, 'foreign-decider');

    expect(($this->policy)()->decide($foreign, ($this->process)(), null))->toBeFalse();
});

it('refuses a decision on an approval that carries no open stage', function (): void {
    ($this->stageOf)(null);
    $this->eligibility->shouldNotReceive('mayDecide');

    AccessContext::grant();

    expect(($this->policy)()->decide(($this->user)(), ($this->process)(['current_stage_position' => null]), null))->toBeFalse();
});

it('hands the decision question over to the eligibility of the open stage, represented person included', function (): void {
    ($this->stageOf)($this->openStage);

    $asked = [];

    $this->eligibility->shouldReceive('mayDecide')
        ->andReturnUsing(function (string $actorId, ApprovalProcessStage $stage, ?string $onBehalfOfId) use (&$asked): bool {
            $asked[] = [$actorId, (string) $stage->getKey(), $onBehalfOfId];

            return $onBehalfOfId === null;
        });

    $actor = ($this->user)(null, 'decider');
    $process = ($this->process)();

    expect(($this->policy)()->decide($actor, $process, null))->toBeTrue()
        ->and(($this->policy)()->decide($actor, $process, ModelStub::ulid('head')))->toBeFalse()
        ->and($asked)->toBe([
            [(string) $actor->getKey(), (string) $this->openStage->getKey(), null],
            [(string) $actor->getKey(), (string) $this->openStage->getKey(), ModelStub::ulid('head')],
        ]);
});

it('refuses a cancellation on an approval of another tenant however powerful the caller is', function (): void {
    AccessContext::grant('approvals.configure');

    $foreign = ($this->user)($this->foreignTenantId, 'foreign-manager');

    expect(($this->policy)()->cancel($foreign, ($this->process)()))->toBeFalse();
});

it('refuses a cancellation on an approval that has already ended', function (ApprovalProcessStatus $status): void {
    AccessContext::grant('approvals.configure');

    expect(($this->policy)()->cancel(($this->user)(), ($this->process)(['status' => $status])))->toBeFalse();
})->with([
    'approved' => [ApprovalProcessStatus::Approved],
    'rejected' => [ApprovalProcessStatus::Rejected],
    'cancelled' => [ApprovalProcessStatus::Cancelled],
]);

it('lets the configurator and the person who triggered it cancel an open approval and nobody else', function (): void {
    AccessContext::grant('approvals.configure');

    expect(($this->policy)()->cancel(($this->user)(null, 'manager'), ($this->process)()))->toBeTrue();

    AccessContext::grant();

    $trigger = AccessContext::user($this->tenant, ['id' => $this->triggerId], 'trigger');
    $stranger = ($this->user)(null, 'stranger');

    expect(($this->policy)()->cancel($trigger, ($this->process)()))->toBeTrue()
        ->and(($this->policy)()->cancel($stranger, ($this->process)()))->toBeFalse();
});

it('refuses to show an approval of another tenant even to a holder of the view permission', function (): void {
    AccessContext::grant('approvals.view');
    $this->subjects->shouldNotReceive('currentStage');

    $foreign = ($this->user)($this->foreignTenantId, 'foreign-viewer');

    expect(($this->policy)()->view($foreign, ($this->process)()))->toBeFalse();
});

it('shows an approval to a holder of the view permission without asking who is eligible', function (): void {
    AccessContext::grant('approvals.view');
    $this->eligibility->shouldNotReceive('eligibleUserIds');
    $this->subjects->shouldNotReceive('currentStage');

    expect(($this->policy)()->view(($this->user)(), ($this->process)()))->toBeTrue();
});

it('shows an approval to the person who triggered it without any permission', function (): void {
    AccessContext::grant();

    $trigger = AccessContext::user($this->tenant, ['id' => $this->triggerId], 'trigger');

    expect(($this->policy)()->view($trigger, ($this->process)()))->toBeTrue();
});

it('shows an approval to an eligible decider of the open stage', function (): void {
    AccessContext::grant();
    ($this->stageOf)($this->openStage);

    $decider = ($this->user)(null, 'decider');

    $this->eligibility->shouldReceive('eligibleUserIds')
        ->with($this->openStage)
        ->andReturn([(string) $decider->getKey()]);

    expect(($this->policy)()->view($decider, ($this->process)()))->toBeTrue();
});

it('falls back to the decision trail of the approval and asks only for the own traces of the caller', function (): void {
    AccessContext::grant();
    ($this->stageOf)($this->openStage);

    $stranger = ($this->user)(null, 'stranger');

    $this->eligibility->shouldReceive('eligibleUserIds')->andReturn([]);

    $shape = QueryShape::attemptedBy(fn (): bool => ($this->policy)()->view($stranger, ($this->process)()));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('approval_events'))->toBeTrue()
        ->and($shape->sql)->toContain('"actor_id" =')
        ->and($shape->sql)->toContain('"on_behalf_of_id" =')
        ->and($shape->hasBinding((string) $stranger->getKey()))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('process')))->toBeTrue();
});
