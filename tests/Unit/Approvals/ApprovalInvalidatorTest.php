<?php

declare(strict_types=1);

use App\Enums\Approvals\ApprovalProcessStatus;
use App\Models\ApprovalProcess;
use App\Models\CustomRecord;
use App\Support\Approvals\ApprovalEventRecorder;
use App\Support\Approvals\ApprovalInvalidator;
use App\Support\Approvals\ApprovalProcessStarter;
use App\Support\Approvals\ApprovalRecordContext;
use App\Support\Approvals\ApprovalSubjectSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->recordContext = Mockery::mock(ApprovalRecordContext::class);
    $this->processStarter = Mockery::mock(ApprovalProcessStarter::class);
    $this->eventRecorder = Mockery::mock(ApprovalEventRecorder::class);
    $this->subjects = Mockery::mock(ApprovalSubjectSource::class);

    $this->invalidator = fn (): ApprovalInvalidator => new ApprovalInvalidator(
        $this->recordContext,
        $this->processStarter,
        $this->eventRecorder,
        $this->subjects,
    );

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('approval-record'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->process = function (string $seed): ApprovalProcess {
        return ModelStub::make(ApprovalProcess::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $this->tenant->getKey(),
            'record_id' => $this->record->getKey(),
            'approval_definition_id' => ModelStub::ulid('definition'),
            'status' => ApprovalProcessStatus::Pending,
            'attempt' => 1,
            'current_stage_position' => 1,
        ]);
    };

    /**
     * @param  list<ApprovalProcess>  $processes
     */
    $this->pending = function (array $processes): void {
        $this->subjects->shouldReceive('lockedPendingProcessesFor')
            ->with($this->record)
            ->andReturn(new Collection($processes));
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('ignores a change that touches no field at all and never even looks for an open process', function (): void {
    $lookups = 0;

    $this->subjects->shouldReceive('lockedPendingProcessesFor')
        ->andReturnUsing(function () use (&$lookups): Collection {
            $lookups++;

            return new Collection;
        });

    ($this->invalidator)()->handleChange($this->record, []);

    expect($lookups)->toBe(0);
});

it('leaves an open approval alone when no field it guards has changed', function (): void {
    ($this->pending)([($this->process)('untouched')]);
    $this->recordContext->shouldReceive('fields')->andReturn(['amount', 'discount']);

    $restarted = [];

    $this->processStarter->shouldReceive('restart')
        ->andReturnUsing(function (ApprovalProcess $process) use (&$restarted): ApprovalProcess {
            $restarted[] = (string) $process->getKey();

            return $process;
        });

    ($this->invalidator)()->handleChange($this->record, ['note', 'title']);

    expect($restarted)->toBe([]);
});

it('restarts an open approval as soon as one guarded field has changed and names that field', function (): void {
    $process = ($this->process)('invalidated');
    ($this->pending)([$process]);
    $this->recordContext->shouldReceive('fields')->andReturn(['amount', 'discount']);

    $reasons = [];

    $this->processStarter->shouldReceive('restart')
        ->once()
        ->andReturnUsing(function (ApprovalProcess $restarted, string $reason) use (&$reasons, $process): ApprovalProcess {
            expect($restarted->getKey())->toBe($process->getKey());
            $reasons[] = $reason;

            return $restarted;
        });

    ($this->invalidator)()->handleChange($this->record, ['title', 'amount']);

    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toContain('amount')
        ->and($reasons[0])->not->toContain('discount')
        ->and($reasons[0])->not->toContain('title');
});

it('asks for the guarded fields including the ones a condition reads', function (): void {
    ($this->pending)([($this->process)('conditional')]);

    $askedWithConditions = [];

    $this->recordContext->shouldReceive('fields')
        ->andReturnUsing(function (ApprovalProcess $process, bool $includeConditions = false) use (&$askedWithConditions): array {
            $askedWithConditions[] = $includeConditions;

            return ['amount'];
        });

    $this->processStarter->shouldReceive('restart')->andReturnUsing(
        static fn (ApprovalProcess $process): ApprovalProcess => $process,
    );

    ($this->invalidator)()->handleChange($this->record, ['amount']);

    expect($askedWithConditions)->toBe([true]);
});

it('keeps working on the remaining approvals when one of them refuses to restart', function (): void {
    Log::spy();

    ($this->pending)([($this->process)('broken'), ($this->process)('healthy')]);
    $this->recordContext->shouldReceive('fields')->andReturn(['amount']);

    $restarted = [];

    $this->processStarter->shouldReceive('restart')
        ->andReturnUsing(function (ApprovalProcess $process, string $reason) use (&$restarted): ApprovalProcess {
            if ($process->getKey() === ModelStub::ulid('broken')) {
                throw ValidationException::withMessages(['stage' => 'Die Stufe hat keine Kandidaten mehr.']);
            }

            $restarted[] = (string) $process->getKey();

            return $process;
        });

    ($this->invalidator)()->handleChange($this->record, ['amount']);

    expect($restarted)->toBe([ModelStub::ulid('healthy')]);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'could not be restarted')
            && $context['approval_process_id'] === ModelStub::ulid('broken'));
});

it('cancels nothing and records nothing when the record carries no open approval', function (): void {
    ($this->pending)([]);
    $this->eventRecorder->shouldNotReceive('record');

    $shape = QueryShape::attemptedBy(fn () => ($this->invalidator)()->cancelOpen($this->record, 'Datensatz gelöscht.'));

    expect($shape)->toBeNull();
});

it('writes the stated reason into the cancelled approval before it records the event', function (): void {
    ($this->pending)([($this->process)('to-cancel')]);
    $this->eventRecorder->shouldNotReceive('record');

    $shape = QueryShape::attemptedBy(
        fn () => ($this->invalidator)()->cancelOpen($this->record, 'Datensatz gelöscht.'),
    );

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('update "approval_processes"')
        ->and($shape->hasBinding(ApprovalProcessStatus::Cancelled->value))->toBeTrue()
        ->and($shape->hasBinding('Datensatz gelöscht.'))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('to-cancel')))->toBeTrue();
});
