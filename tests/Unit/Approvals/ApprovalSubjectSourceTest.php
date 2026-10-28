<?php

declare(strict_types=1);

use App\Enums\Approvals\ApprovalEventType;
use App\Enums\Approvals\ApprovalProcessStatus;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\CustomRecord;
use App\Support\Approvals\ApprovalSubjectSource;
use App\Support\Tenancy\TenantBinder;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->source = new ApprovalSubjectSource(new TenantBinder);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('approval-record'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->process = ModelStub::make(ApprovalProcess::class, [
        'id' => ModelStub::ulid('process'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $this->record->getKey(),
        'approval_definition_id' => ModelStub::ulid('definition'),
        'status' => ApprovalProcessStatus::Pending,
        'attempt' => 3,
        'current_stage_position' => 2,
    ]);

    $this->stage = ModelStub::make(ApprovalProcessStage::class, [
        'id' => ModelStub::ulid('process-stage'),
        'tenant_id' => $this->tenant->getKey(),
        'approval_process_id' => $this->process->getKey(),
        'approval_definition_stage_id' => ModelStub::ulid('definition-stage'),
        'position' => 2,
        'attempt' => 3,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('binds the open stage of a process to the running attempt so a superseded stage never returns', function (): void {
    $shape = QueryShape::attemptedBy(fn (): ?ApprovalProcessStage => $this->source->currentStage($this->process));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('approval_process_stages'))->toBeTrue()
        ->and($shape->isScopedToTenant('approval_process_stages', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('approval_process_stages'))->toBeTrue()
        ->and($shape->sql)->toContain('"attempt" =')
        ->and($shape->sql)->toContain('"position" =')
        ->and($shape->hasBinding(3))->toBeTrue()
        ->and($shape->hasBinding(2))->toBeTrue()
        ->and($shape->hasBinding($this->process->getKey()))->toBeTrue();
});

it('asks for no stage at all once a process has closed its current position', function (): void {
    $closed = ModelStub::make(ApprovalProcess::class, [
        'id' => ModelStub::ulid('closed-process'),
        'tenant_id' => $this->tenant->getKey(),
        'status' => ApprovalProcessStatus::Approved,
        'attempt' => 3,
        'current_stage_position' => null,
    ]);

    $shape = QueryShape::attemptedBy(fn (): ?ApprovalProcessStage => $this->source->currentStage($closed));

    expect($shape)->toBeNull();
});

it('locks only the open approvals of the very record and tenant it was handed', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->source->lockedPendingProcessesFor($this->record));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('approval_processes'))->toBeTrue()
        ->and($shape->isScopedToTenant('approval_processes', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('approval_processes'))->toBeTrue()
        ->and($shape->hasBinding($this->record->getKey()))->toBeTrue()
        ->and($shape->hasBinding(ApprovalProcessStatus::Pending->value))->toBeTrue()
        ->and($shape->sql)->toContain('for update');
});

it('counts only the approvals that were given on this very stage', function (): void {
    $shape = QueryShape::attemptedBy(fn (): int => $this->source->approvedDecisionCount($this->stage));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('approval_events'))->toBeTrue()
        ->and($shape->isScopedToTenant('approval_events', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding($this->stage->getKey()))->toBeTrue()
        ->and($shape->hasBinding(ApprovalEventType::Approved->value))->toBeTrue();
});

it('looks for the recipients of a notification only inside the tenant of the approval', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->source->recipients(
        (string) $this->tenant->getKey(),
        [ModelStub::ulid('head'), ModelStub::ulid('clerk')],
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" =')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('head')))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('clerk')))->toBeTrue();
});

it('still resolves the record of an approval after the record has been deleted', function (): void {
    $shape = QueryShape::attemptedBy(fn (): ?CustomRecord => $this->source->record($this->process));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeFalse()
        ->and($shape->hasBinding($this->record->getKey()))->toBeTrue();
});

it('asks for no record at all when the approval is anchored without one', function (): void {
    $anchorOnly = ModelStub::make(ApprovalProcess::class, [
        'id' => ModelStub::ulid('anchor-only-process'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => null,
        'attempt' => 1,
    ]);

    expect(QueryShape::attemptedBy(fn (): ?CustomRecord => $this->source->record($anchorOnly)))->toBeNull()
        ->and(QueryShape::attemptedBy(fn (): ?CustomRecord => $this->source->recordIfStillThere($anchorOnly)))->toBeNull();
});

it('keeps resolving the frozen definition stage after the definition has been reconfigured', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->source->definitionStage($this->stage));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('approval_definition_stages'))->toBeTrue()
        ->and($shape->hidesSoftDeleted('approval_definition_stages'))->toBeFalse()
        ->and($shape->hasBinding(ModelStub::ulid('definition-stage')))->toBeTrue();
});

it('pins every approval table of a query to the bound tenant and hides what was deleted', function (string $model, string $table): void {
    $shape = QueryShape::of($model);

    expect($shape->isScopedToTenant($table, (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->blocksEveryRow())->toBeFalse();
})->with([
    'processes' => [ApprovalProcess::class, 'approval_processes'],
    'stages' => [ApprovalProcessStage::class, 'approval_process_stages'],
]);

it('blocks every approval row when no tenant is bound at all', function (): void {
    AccessContext::forgetTenant();

    expect(QueryShape::of(ApprovalProcess::class)->blocksEveryRow())->toBeTrue()
        ->and(QueryShape::of(ApprovalProcessStage::class)->blocksEveryRow())->toBeTrue();
});
