<?php

declare(strict_types=1);

use App\Support\Engine\RecordChangeRelayStarter;
use Illuminate\Support\Facades\DB;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowRunInterface;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config()->set('record-processing.schedules.relay_immediate', true);

    $this->starts = new stdClass;
    $this->starts->count = 0;

    $client = Mockery::mock(WorkflowClientInterface::class);
    $client->shouldReceive('newWorkflowStub')->andReturn(new stdClass);
    $client->shouldReceive('start')->andReturnUsing(function (): WorkflowRunInterface {
        $this->starts->count++;

        return Mockery::mock(WorkflowRunInterface::class);
    });

    app()->instance(WorkflowClientInterface::class, $client);
    app()->forgetInstance(RecordChangeRelayStarter::class);

    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('beginTransaction', 'commit', 'rollBack', 'inTransaction')->andReturn(true);
    $pdo->shouldReceive('exec')->andReturn(0);
    DB::connection()->setPdo($pdo);

    $this->starter = app(RecordChangeRelayStarter::class);
});

afterEach(function (): void {
    Mockery::close();
});

it('shares one pending relay request between every starter the request resolves', function (): void {
    DB::transaction(function (): void {
        $this->starter->startAfterCommit();
        app(RecordChangeRelayStarter::class)->startAfterCommit();
    });

    expect(app(RecordChangeRelayStarter::class))->not->toBe($this->starter)
        ->and($this->starts->count)->toBe(1);
});

it('triggers the relay at once when no transaction is open', function (): void {
    $this->starter->startAfterCommit();

    expect($this->starts->count)->toBe(1);
});

it('triggers the relay once after the outermost commit however many entries the transaction wrote', function (): void {
    $startsBeforeCommit = DB::transaction(function (): int {
        $this->starter->startAfterCommit();
        $this->starter->startAfterCommit();

        DB::transaction(function (): void {
            $this->starter->startAfterCommit();
        });

        return $this->starts->count;
    });

    expect($startsBeforeCommit)->toBe(0)
        ->and($this->starts->count)->toBe(1);
});

it('triggers nothing for a transaction that rolled back and again for the next one that commits', function (): void {
    expect(fn () => DB::transaction(function (): void {
        $this->starter->startAfterCommit();

        throw new RuntimeException('write failed');
    }))->toThrow(RuntimeException::class);

    expect($this->starts->count)->toBe(0);

    DB::transaction(function (): void {
        $this->starter->startAfterCommit();
    });

    expect($this->starts->count)->toBe(1);
});

it('starts through the workflow client bound when it is resolved, not the one bound when the application booted', function (): void {
    app(RecordChangeRelayStarter::class);

    $swapped = Mockery::mock(WorkflowClientInterface::class);
    $swapped->shouldReceive('newWorkflowStub')->once()->andReturn(new stdClass);
    $swapped->shouldReceive('start')->once()->andReturn(Mockery::mock(WorkflowRunInterface::class));
    app()->instance(WorkflowClientInterface::class, $swapped);

    app(RecordChangeRelayStarter::class)->startNow();

    expect($this->starts->count)->toBe(0);
});
