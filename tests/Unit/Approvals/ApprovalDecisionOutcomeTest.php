<?php

declare(strict_types=1);

use App\Actions\Approvals\CancelApprovalProcessAction;
use App\Actions\Approvals\DecideApprovalAction;
use App\Contracts\Approvals\ApprovalOutcomeHandler;
use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Enums\Approvals\ApprovalStageStatus;
use App\Models\ApprovalDefinitionStage;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Support\Approvals\ApprovalEventRecorder;
use App\Support\Approvals\ApprovalNotifier;
use App\Support\Approvals\ApprovalOutcomeRegistry;
use App\Support\Approvals\ApprovalProcessStarter;
use App\Support\Approvals\ApprovalQuorumEvaluator;
use App\Support\Approvals\ApprovalStageEligibility;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant, [], 'approval-actor'));
    $this->standInId = ModelStub::ulid('approval-stand-in');
    $this->delegatorId = ModelStub::ulid('approval-delegator');
    $this->defaultCancellationReason = __('i18n.backend.actions.approvals.cancel_approval_process_action.cancelled_by_an_authorised_person');

    $this->process = ModelStub::make(ApprovalProcess::class, [
        'id' => ModelStub::ulid('approval-process'),
        'tenant_id' => $this->tenant->getKey(),
        'approval_definition_id' => ModelStub::ulid('approval-definition'),
        'anchor_type' => 'record_transition',
        'status' => ApprovalProcessStatus::Pending->value,
        'attempt' => 1,
        'current_stage_position' => 1,
    ]);

    $this->stageRow = [
        'id' => ModelStub::ulid('approval-stage'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'approval_process_id' => (string) $this->process->getKey(),
        'approval_definition_stage_id' => ModelStub::ulid('approval-definition-stage'),
        'position' => 1,
        'attempt' => 1,
        'status' => ApprovalStageStatus::Pending->value,
    ];

    $this->nextDefinitionStageRow = [
        'id' => ModelStub::ulid('approval-definition-stage-two'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'approval_definition_id' => (string) $this->process->approval_definition_id,
        'position' => 2,
    ];

    /** @var callable(array<string, mixed>, bool):StaticQueryConnection */
    $this->connectionFor = function (array $processOverrides = [], bool $withLaterStage = false): StaticQueryConnection {
        $processRow = [
            'id' => (string) $this->process->getKey(),
            'tenant_id' => (string) $this->tenant->getKey(),
            'approval_definition_id' => (string) $this->process->approval_definition_id,
            'anchor_type' => 'record_transition',
            'anchor_id' => ModelStub::ulid('approval-anchor'),
            'status' => ApprovalProcessStatus::Pending->value,
            'attempt' => 1,
            'current_stage_position' => 1,
            ...$processOverrides,
        ];

        return StaticQueryConnection::install(
            function (string $sql) use ($processRow, $withLaterStage): array {
                if (str_contains($sql, 'from "approval_processes"')) {
                    return [$processRow];
                }

                if (str_contains($sql, 'from "approval_process_stages"')) {
                    return $processRow['current_stage_position'] === null ? [] : [$this->stageRow];
                }

                if (str_contains($sql, 'from "approval_definition_stages"')) {
                    return $withLaterStage ? [$this->nextDefinitionStageRow] : [];
                }

                return [];
            },
            static fn (): int => 1,
        );
    };

    $this->eligibility = Mockery::mock(ApprovalStageEligibility::class);
    $this->quorum = Mockery::mock(ApprovalQuorumEvaluator::class);
    $this->eventRecorder = Mockery::mock(ApprovalEventRecorder::class);
    $this->processStarter = Mockery::mock(ApprovalProcessStarter::class);
    $this->notifier = Mockery::mock(ApprovalNotifier::class);
    $this->outcomeHandler = Mockery::mock(ApprovalOutcomeHandler::class);

    $this->event = ModelStub::make(ApprovalEvent::class, [
        'id' => ModelStub::ulid('approval-event'),
        'tenant_id' => $this->tenant->getKey(),
        'approval_process_id' => $this->process->getKey(),
        'type' => ApprovalEventType::Approved->value,
    ]);

    $outcomes = Mockery::mock(ApprovalOutcomeRegistry::class);
    $outcomes->shouldReceive('for')->andReturn($this->outcomeHandler);

    $this->decide = new DecideApprovalAction(
        $this->eligibility,
        $this->quorum,
        $this->eventRecorder,
        $this->processStarter,
        $outcomes,
        $this->notifier,
    );

    $this->cancel = new CancelApprovalProcessAction($this->eventRecorder);

    $this->outcomeHandler->shouldReceive('recordDecision')->byDefault();
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('hands the row it locked and the requested stand-in to the gate', function (): void {
    ($this->connectionFor)();
    $gate = GateSpy::allowing('decide');

    $this->eventRecorder->shouldReceive('record')->andReturn($this->event);
    $this->quorum->shouldReceive('isSatisfied')->andReturn(false);

    $this->decide->execute($this->actor, $this->process, [
        'decision' => ApprovalEventType::Approved->value,
        'onBehalfOfId' => $this->standInId,
    ]);

    expect($gate->calls)->toHaveCount(1)
        ->and($gate->calls[0]['ability'])->toBe('decide')
        ->and($gate->calls[0]['arguments'][0])->toBeInstanceOf(ApprovalProcess::class)
        ->and($gate->calls[0]['arguments'][0])->not->toBe($this->process)
        ->and((string) $gate->calls[0]['arguments'][0]->getKey())->toBe((string) $this->process->getKey())
        ->and($gate->calls[0]['arguments'][1])->toBe($this->standInId);
});

it('never asks the gate about a process that has no open stage left', function (): void {
    ($this->connectionFor)(['current_stage_position' => null]);
    $gate = GateSpy::allowing('decide');

    expect(fn (): ApprovalProcess => $this->decide->execute($this->actor, $this->process, [
        'decision' => ApprovalEventType::Approved->value,
    ]))->toThrow(ValidationException::class);

    expect($gate->calls)->toBe([]);
});

it('refuses a decision on a process that the locked row shows as finished', function (): void {
    ($this->connectionFor)(['status' => ApprovalProcessStatus::Approved->value]);
    $gate = GateSpy::allowing('decide');

    expect(fn (): ApprovalProcess => $this->decide->execute($this->actor, $this->process, [
        'decision' => ApprovalEventType::Approved->value,
    ]))->toThrow(ValidationException::class);

    expect($gate->calls)->toBe([]);
});

it('falls back to the escalation delegator when the actor names no stand-in', function (): void {
    ($this->connectionFor)();
    GateSpy::allowing('decide');

    $this->eligibility->shouldReceive('escalationDelegatorFor')
        ->once()
        ->with((string) $this->actor->getKey(), Mockery::type(ApprovalProcessStage::class))
        ->andReturn($this->delegatorId);

    $this->eventRecorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (ApprovalProcess $process, ApprovalEventType $type, array $context): bool => $context['on_behalf_of_id'] === $this->delegatorId)
        ->andReturn($this->event);

    $this->quorum->shouldReceive('isSatisfied')->andReturn(false);

    $this->decide->execute($this->actor, $this->process, ['decision' => ApprovalEventType::Approved->value]);
});

it('keeps the requested stand-in instead of looking for a delegator', function (): void {
    ($this->connectionFor)();
    GateSpy::allowing('decide');

    $this->eligibility->shouldNotReceive('escalationDelegatorFor');

    $this->eventRecorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (ApprovalProcess $process, ApprovalEventType $type, array $context): bool => $context['on_behalf_of_id'] === $this->standInId)
        ->andReturn($this->event);

    $this->quorum->shouldReceive('isSatisfied')->andReturn(false);

    $this->decide->execute($this->actor, $this->process, [
        'decision' => ApprovalEventType::Approved->value,
        'onBehalfOfId' => $this->standInId,
    ]);
});

it('writes nothing at all when the quorum is not satisfied yet', function (): void {
    $connection = ($this->connectionFor)();
    GateSpy::allowing('decide');

    $this->eligibility->shouldReceive('escalationDelegatorFor')->andReturn(null);
    $this->eventRecorder->shouldReceive('record')->once()->andReturn($this->event);
    $this->quorum->shouldReceive('isSatisfied')->once()->andReturn(false);

    $this->notifier->shouldNotReceive('notifyDecision');
    $this->outcomeHandler->shouldNotReceive('applyApproved');

    $this->decide->execute($this->actor, $this->process, ['decision' => ApprovalEventType::Approved->value]);

    expect($connection->writtenStatements)->toBe([]);
});

it('moves on to the next stage the definition still holds instead of finishing', function (): void {
    $connection = ($this->connectionFor)([], true);
    GateSpy::allowing('decide');

    $this->eligibility->shouldReceive('escalationDelegatorFor')->andReturn(null);
    $this->quorum->shouldReceive('isSatisfied')->andReturn(true);

    $nextStage = ModelStub::make(ApprovalProcessStage::class, [
        'id' => ModelStub::ulid('approval-stage-two'),
        'tenant_id' => $this->tenant->getKey(),
        'approval_process_id' => $this->process->getKey(),
        'position' => 2,
        'attempt' => 1,
        'status' => ApprovalStageStatus::Pending->value,
    ]);

    $this->processStarter->shouldReceive('startStage')
        ->once()
        ->withArgs(fn (ApprovalProcess $process, ApprovalDefinitionStage $definitionStage, int $attempt): bool => $definitionStage->position === 2 && $attempt === 1)
        ->andReturn($nextStage);

    $recorded = [];

    $this->eventRecorder->shouldReceive('record')
        ->andReturnUsing(function (ApprovalProcess $process, ApprovalEventType $type) use (&$recorded): ApprovalEvent {
            $recorded[] = $type;

            return $this->event;
        });

    $this->notifier->shouldReceive('notifyStageStarted')->once()->with($nextStage);
    $this->notifier->shouldNotReceive('notifyDecision');
    $this->outcomeHandler->shouldNotReceive('applyApproved');

    $this->decide->execute($this->actor, $this->process, ['decision' => ApprovalEventType::Approved->value]);

    expect($recorded)->toBe([ApprovalEventType::Approved, ApprovalEventType::StageStarted])
        ->and($connection->writtenSqlOf('approval_processes'))->toHaveCount(1)
        ->and($connection->writtenStatements[1]['bindings'])->toContain(2);
});

it('finishes the process once no later stage remains', function (): void {
    ($this->connectionFor)();
    GateSpy::allowing('decide');

    $this->eligibility->shouldReceive('escalationDelegatorFor')->andReturn(null);
    $this->quorum->shouldReceive('isSatisfied')->andReturn(true);
    $this->processStarter->shouldNotReceive('startStage');

    $recorded = [];

    $this->eventRecorder->shouldReceive('record')
        ->andReturnUsing(function (ApprovalProcess $process, ApprovalEventType $type) use (&$recorded): ApprovalEvent {
            $recorded[] = $type;

            return $this->event;
        });

    $this->outcomeHandler->shouldReceive('applyApproved')->once();
    $this->notifier->shouldReceive('notifyDecision')->once()->with($this->event);

    $this->decide->execute($this->actor, $this->process, ['decision' => ApprovalEventType::Approved->value]);

    expect($recorded)->toBe([ApprovalEventType::Approved, ApprovalEventType::Completed]);
});

it('ends a rejected process without ever consulting the quorum', function (): void {
    $connection = ($this->connectionFor)([], true);
    GateSpy::allowing('decide');

    $this->eligibility->shouldReceive('escalationDelegatorFor')->andReturn(null);
    $this->quorum->shouldNotReceive('isSatisfied');
    $this->processStarter->shouldNotReceive('startStage');

    $this->eventRecorder->shouldReceive('record')->andReturn($this->event);
    $this->outcomeHandler->shouldReceive('applyRejected')->once()->with(Mockery::type(ApprovalProcess::class), 'Budget exceeded');
    $this->notifier->shouldReceive('notifyDecision')->once();

    $this->decide->execute($this->actor, $this->process, [
        'decision' => ApprovalEventType::Rejected->value,
        'reason' => 'Budget exceeded',
    ]);

    expect($connection->writtenStatements[0]['bindings'])->toContain(ApprovalStageStatus::Rejected->value)
        ->and($connection->writtenStatements[1]['bindings'])->toContain(ApprovalProcessStatus::Rejected->value);
});

it('authorises the cancellation against the row it locked', function (): void {
    ($this->connectionFor)();
    $gate = GateSpy::allowing('cancel');

    $this->eventRecorder->shouldReceive('record')->andReturn($this->event);

    $this->cancel->execute($this->actor, $this->process, []);

    expect($gate->calls)->toHaveCount(1)
        ->and($gate->calls[0]['ability'])->toBe('cancel')
        ->and($gate->calls[0]['arguments'][0])->not->toBe($this->process);
});

it('falls back to the default reason when the cancellation names none', function (): void {
    $connection = ($this->connectionFor)();
    GateSpy::allowing('cancel');

    $this->eventRecorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (ApprovalProcess $process, ApprovalEventType $type, array $context): bool => $context['reason'] === $this->defaultCancellationReason)
        ->andReturn($this->event);

    $this->cancel->execute($this->actor, $this->process, ['reason' => '']);

    expect($connection->writtenStatements[1]['bindings'])->toContain($this->defaultCancellationReason);
});

it('keeps a cancellation reason the actor did supply', function (): void {
    ($this->connectionFor)();
    GateSpy::allowing('cancel');

    $this->eventRecorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (ApprovalProcess $process, ApprovalEventType $type, array $context): bool => $context['reason'] === 'Superseded by a newer request')
        ->andReturn($this->event);

    $this->cancel->execute($this->actor, $this->process, ['reason' => 'Superseded by a newer request']);
});

it('supersedes only an open stage while cancelling', function (): void {
    $connection = ($this->connectionFor)(['current_stage_position' => null]);
    GateSpy::allowing('cancel');

    $this->eventRecorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (ApprovalProcess $process, ApprovalEventType $type, array $context): bool => $context['stage'] === null)
        ->andReturn($this->event);

    $this->cancel->execute($this->actor, $this->process, []);

    expect($connection->writtenSqlOf('approval_process_stages'))->toBe([])
        ->and($connection->writtenSqlOf('approval_processes'))->toHaveCount(1);
});
