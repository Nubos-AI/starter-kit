<?php

declare(strict_types=1);

use App\Actions\Formulas\CancelFormulaBackfillAction;
use App\Contracts\Formulas\FormulaBackfillWorkflowInterface;
use App\Enums\Formulas\BackfillStatus;
use App\Http\Resources\Formulas\FormulaBackfillRunResource;
use App\Models\FormulaBackfillRun;
use App\Models\ObjectType;
use App\Support\Formulas\FormulaBackfillProgress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Temporal\Client\WorkflowClientInterface;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('cancel-object-type');
    $this->fieldDefinitionId = ModelStub::ulid('cancel-field');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'invoices',
    ]);

    /** @var callable(array<string, mixed>):FormulaBackfillRun */
    $this->run = fn (array $attributes = []): FormulaBackfillRun => ModelStub::make(
        FormulaBackfillRun::class,
        [
            'id' => ModelStub::ulid('cancel-run'),
            'tenant_id' => $this->tenant->getKey(),
            'field_definition_id' => $this->fieldDefinitionId,
            'object_type_id' => $this->objectTypeId,
            'status' => BackfillStatus::Running,
            'total_count' => 120,
            'processed_count' => 40,
            'error_count' => 2,
            ...$attributes,
        ],
        ['objectType' => $this->objectType],
    );

    $this->workflowStub = Mockery::spy();
    $this->workflowClient = Mockery::spy(WorkflowClientInterface::class);
    $this->workflowClient->shouldReceive('newRunningWorkflowStub')->andReturn($this->workflowStub);

    /** @var callable(?bool):FormulaBackfillProgress */
    $this->progress = function (?bool $finalized): FormulaBackfillProgress {
        $progress = Mockery::spy(FormulaBackfillProgress::class);

        if ($finalized !== null) {
            $progress->shouldReceive('finalize')->andReturn($finalized);
        }

        return $progress;
    };

    $this->expectedWorkflowId = "formula-backfill:{$this->tenant->getKey()}:{$this->fieldDefinitionId}";
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

test('the run payload carries exactly the six documented keys with the status as its enum value', function (): void {
    $run = ($this->run)(['finished_at' => Carbon::parse('2026-07-01 09:00:00')]);

    $payload = (new FormulaBackfillRunResource($run))->toArray(Request::create('/'));

    expect(array_keys($payload))->toEqualCanonicalizing([
        'id',
        'status',
        'totalCount',
        'processedCount',
        'errorCount',
        'finishedAt',
    ])
        ->and($payload['id'])->toBe((string) $run->getKey())
        ->and($payload['status'])->toBe(BackfillStatus::Running->value)
        ->and($payload['totalCount'])->toBe(120)
        ->and($payload['processedCount'])->toBe(40)
        ->and($payload['errorCount'])->toBe(2)
        ->and($payload['finishedAt'])->toBe('2026-07-01T09:00:00+00:00');
});

test('an unfinished run reports no finishing time', function (): void {
    $payload = (new FormulaBackfillRunResource(($this->run)()))->toArray(Request::create('/'));

    expect($payload['finishedAt'])->toBeNull();
});

test('a caller who may not update the object type cannot cancel and the run is never finalized', function (): void {
    $gate = GateSpy::allowing();
    $progress = ($this->progress)(null);

    $action = new CancelFormulaBackfillAction($progress, $this->workflowClient);

    expect(fn (): bool => $action->execute(($this->run)()))->toThrow(AuthorizationException::class);

    expect($gate->wasAskedFor('update'))->toBeTrue();

    $progress->shouldNotHaveReceived('finalize');
    $this->workflowClient->shouldNotHaveReceived('newRunningWorkflowStub');
    $this->workflowStub->shouldNotHaveReceived('cancel');
});

test('the authorization is asked about the object type the run belongs to', function (): void {
    $gate = GateSpy::allowing('update');

    (new CancelFormulaBackfillAction(($this->progress)(true), $this->workflowClient))
        ->execute(($this->run)());

    expect($gate->calls[0]['ability'])->toBe('update')
        ->and($gate->calls[0]['arguments'][0] ?? null)->toBe($this->objectType);
});

test('a run that already ended is not signalled again', function (): void {
    GateSpy::allowing('update');

    $result = (new CancelFormulaBackfillAction(($this->progress)(false), $this->workflowClient))
        ->execute(($this->run)(['status' => BackfillStatus::Completed]));

    expect($result)->toBeFalse();

    $this->workflowClient->shouldNotHaveReceived('newRunningWorkflowStub');
    $this->workflowStub->shouldNotHaveReceived('cancel');
});

test('an open run is signalled on the deterministic workflow id of its tenant and field definition', function (): void {
    GateSpy::allowing('update');

    $result = (new CancelFormulaBackfillAction(($this->progress)(true), $this->workflowClient))
        ->execute(($this->run)());

    expect($result)->toBeTrue();

    $this->workflowClient->shouldHaveReceived('newRunningWorkflowStub')
        ->once()
        ->withArgs(fn (mixed ...$arguments): bool => ($arguments[0] ?? null) === FormulaBackfillWorkflowInterface::class
            && ($arguments[1] ?? null) === $this->expectedWorkflowId);

    $this->workflowStub->shouldHaveReceived('cancel')->once();
});

test('a workflow client that throws leaves the run cancelled and logs the run and workflow id', function (): void {
    GateSpy::allowing('update');
    Log::spy();

    $failingClient = Mockery::mock(WorkflowClientInterface::class);
    $failingClient->shouldReceive('newRunningWorkflowStub')
        ->andThrow(new RuntimeException('temporal frontend unreachable'));

    $run = ($this->run)();

    $result = (new CancelFormulaBackfillAction(($this->progress)(true), $failingClient))->execute($run);

    expect($result)->toBeTrue();

    Log::shouldHaveReceived('warning')->withArgs(
        function (string $message, array $context = []) use ($run): bool {
            return ($context['backfill_run_id'] ?? null) === (string) $run->getKey()
                && ($context['workflow_id'] ?? null) === $this->expectedWorkflowId
                && str_contains((string) ($context['reason'] ?? ''), 'temporal frontend unreachable');
        },
    )->once();
});
