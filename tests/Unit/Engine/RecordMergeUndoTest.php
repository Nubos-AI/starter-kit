<?php

declare(strict_types=1);

use App\Actions\Engine\MergeRecordsAction;
use App\Actions\Engine\UndoRecordMergeAction;
use App\DTOs\Engine\MergeUndoReport;
use App\Enums\Engine\MergeUndoRefusalReason;
use App\Exceptions\Engine\MergeUndoRefusedException;
use App\Http\Controllers\Engine\RecordMergeController;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordMerge;
use App\Models\User;
use App\Support\Engine\MergePlanner;
use App\Support\Engine\MergeTransferCounter;
use App\Support\Engine\RollupOwnerStarter;
use App\Support\Timeline\TimelineRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('target-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->undoAction = new UndoRecordMergeAction(
        Mockery::mock(MergeTransferCounter::class),
        Mockery::mock(TimelineRecorder::class),
        Mockery::mock(RollupOwnerStarter::class),
    );

    /** @var callable(TimelineRecorder):UndoRecordMergeAction */
    $this->undoActionWith = fn (TimelineRecorder $timeline): UndoRecordMergeAction => new UndoRecordMergeAction(
        Mockery::mock(MergeTransferCounter::class),
        $timeline,
        Mockery::mock(RollupOwnerStarter::class)->shouldIgnoreMissing(),
    );

    $this->undoRecordMerge = Mockery::mock(UndoRecordMergeAction::class);

    $this->controller = new RecordMergeController(
        Mockery::mock(MergePlanner::class),
        Mockery::mock(MergeRecordsAction::class),
        $this->undoRecordMerge,
    );

    /** @var callable(array<string, mixed>):RecordMerge */
    $this->merge = fn (array $overrides = []): RecordMerge => ModelStub::make(RecordMerge::class, [
        'id' => ModelStub::ulid('merge'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'target_record_id' => (string) $this->record->getKey(),
        'source_record_id' => ModelStub::ulid('source-record'),
        'created_at' => now(),
        'undone_at' => null,
        ...$overrides,
    ]);

    /** @var callable():Request */
    $this->request = function (): Request {
        $request = Request::create('/probe', 'POST');
        $user = AccessContext::user($this->tenant);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to undo a merge that was already taken back', function (): void {
    $merge = ($this->merge)(['undone_at' => now()->subMinute()]);

    $refusal = null;

    try {
        $this->undoAction->execute($merge);
    } catch (MergeUndoRefusedException $exception) {
        $refusal = $exception->reason;
    }

    expect($refusal)->toBe(MergeUndoRefusalReason::AlreadyUndone);
});

it('refuses to undo a merge that has left its undo window', function (): void {
    config()->set('engine.merge.undo_window_days', 30);

    $merge = ($this->merge)(['created_at' => now()->subDays(31)]);

    $refusal = null;

    try {
        $this->undoAction->execute($merge);
    } catch (MergeUndoRefusedException $exception) {
        $refusal = $exception->reason;
    }

    expect($refusal)->toBe(MergeUndoRefusalReason::WindowExpired);
});

it('lets a merge inside its undo window through to the restore', function (): void {
    config()->set('engine.merge.undo_window_days', 30);

    expect(fn (): MergeUndoReport => $this->undoAction->execute(($this->merge)(['created_at' => now()->subDays(29)])))
        ->toThrow(PDOException::class);
});

it('keeps every merge undoable when no window is configured at all', function (): void {
    config()->set('engine.merge.undo_window_days', 0);

    expect(fn (): MergeUndoReport => $this->undoAction->execute(($this->merge)(['created_at' => now()->subYears(5)])))
        ->toThrow(PDOException::class);
});

it('refuses an undo for an actor without the merge ability', function (): void {
    GateSpy::allowing();
    $this->undoRecordMerge->shouldNotReceive('execute');

    expect(fn (): JsonResponse => $this->controller->undo(($this->request)(), $this->record, ($this->merge)()))
        ->toThrow(AuthorizationException::class);
});

it('refuses to take a merge back through a record it does not belong to', function (): void {
    GateSpy::allowing('merge');
    $this->undoRecordMerge->shouldNotReceive('execute');

    $foreign = ($this->merge)(['target_record_id' => ModelStub::ulid('other-record')]);

    expect(fn (): JsonResponse => $this->controller->undo(($this->request)(), $this->record, $foreign))
        ->toThrow(NotFoundHttpException::class);
});

it('answers a refused undo with its reason instead of an error page', function (): void {
    GateSpy::allowing('merge');

    $this->undoRecordMerge->shouldReceive('execute')
        ->once()
        ->andThrow(new MergeUndoRefusedException(MergeUndoRefusalReason::AlreadyUndone));

    $response = $this->controller->undo(($this->request)(), $this->record, ($this->merge)());

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['reason'])->toBe('already_undone');
});

it('reports which fields came back and which the record kept', function (): void {
    GateSpy::allowing('merge');

    $this->undoRecordMerge->shouldReceive('execute')
        ->once()
        ->andReturn(new MergeUndoReport(
            ModelStub::ulid('merge'),
            (string) $this->record->getKey(),
            ModelStub::ulid('source-record'),
            ['volume'],
            ['title'],
            ['notes' => 1],
            ['links' => 1],
        ));

    $response = $this->controller->undo(($this->request)(), $this->record, ($this->merge)());

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['data']['restored_fields'])->toBe(['volume'])
        ->and($response->getData(true)['data']['kept_fields'])->toBe(['title'])
        ->and($response->getData(true)['data']['unrecoverable'])->toBe(['links' => 1]);
});

it('turns a unique violation while the source comes back into a refusal instead of an error', function (): void {
    config()->set('engine.merge.undo_window_days', 0);

    $merge = ($this->merge)([]);

    StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => str_contains($sql, 'from "custom_records"')
            ? [[
                'id' => (string) $bindings[0],
                'tenant_id' => (string) $this->tenant->getKey(),
                'object_type_id' => (string) $this->objectType->getKey(),
                'version' => 2,
                'data' => '{}',
            ]]
            : [],
        static fn (): int => throw new QueryException(
            'static',
            'update "custom_records" set "external_reference_id" = ?',
            [],
            new PDOException('SQLSTATE[23505]: Unique violation: duplicate key value'),
        ),
    );

    $refusal = null;

    try {
        ($this->undoActionWith)(Mockery::mock(TimelineRecorder::class))->execute($merge);
    } catch (MergeUndoRefusedException $exception) {
        $refusal = $exception->reason;
    }

    StaticQueryConnection::uninstall();

    expect($refusal)->toBe(MergeUndoRefusalReason::IdentifierTaken);
});

it('brings back only the fields the target still carries from the merge and reports the rest as kept', function (): void {
    config()->set('engine.merge.undo_window_days', 0);

    $merge = ($this->merge)([
        'resolution' => ['fields' => [
            'city' => ['before' => 'Karlsruhe', 'after' => 'Berlin'],
            'name' => ['before' => 'Acme', 'after' => 'Acme AG'],
            'note' => ['before' => 'same', 'after' => 'same'],
        ]],
        'transfers' => [],
    ]);

    $connection = StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => str_contains($sql, 'from "custom_records"')
            ? [[
                'id' => (string) $bindings[0],
                'tenant_id' => (string) $this->tenant->getKey(),
                'object_type_id' => (string) $this->objectType->getKey(),
                'version' => 2,
                'data' => '{"city":"Berlin","name":"Changed by hand"}',
            ]]
            : [],
        static fn (): int => 1,
    );

    $timeline = Mockery::mock(TimelineRecorder::class);
    $timeline->shouldReceive('record')->twice();

    $report = ($this->undoActionWith)($timeline)->execute($merge);

    StaticQueryConnection::uninstall();

    expect($report->restoredFields)->toBe(['city'])
        ->and($report->keptFields)->toBe(['name'])
        ->and($connection->writtenSqlOf('custom_records'))->toHaveCount(2);
});
