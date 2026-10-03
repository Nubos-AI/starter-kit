<?php

declare(strict_types=1);

use App\Actions\Engine\MergeRecordsAction;
use App\Actions\Engine\UndoRecordMergeAction;
use App\DTOs\Engine\MergeRequestData;
use App\Exceptions\Engine\MergeRefusedException;
use App\Http\Controllers\Engine\RecordMergeController;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordMerge;
use App\Models\User;
use App\Support\Engine\MergePlanner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
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
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->stranger = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('stranger-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->mergeRecords = Mockery::mock(MergeRecordsAction::class);
    $this->undoRecordMerge = Mockery::mock(UndoRecordMergeAction::class);

    $this->controller = new RecordMergeController(
        Mockery::mock(MergePlanner::class),
        $this->mergeRecords,
        $this->undoRecordMerge,
    );

    /** @var callable(array<string, mixed>):Request */
    $this->request = function (array $payload): Request {
        $request = Request::create('/probe', 'POST', $payload);
        $user = AccessContext::user($this->tenant);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };

    /** @var callable(array<string, mixed>):array<string, mixed> */
    $this->pair = fn (array $overrides = []): array => [
        'targetId' => (string) $this->record->getKey(),
        'sourceId' => (string) $this->record->getKey(),
        ...$overrides,
    ];
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a merge for an actor without the merge ability and never runs it', function (): void {
    $gate = GateSpy::allowing();
    $this->mergeRecords->shouldNotReceive('execute');

    expect(fn (): JsonResponse => $this->controller->store(($this->request)(($this->pair)()), $this->record))
        ->toThrow(AuthorizationException::class)
        ->and($gate->abilities())->toBe(['merge']);
});

it('refuses a merge that does not involve the record it was started from', function (): void {
    GateSpy::allowing('merge', 'update', 'delete');
    $this->mergeRecords->shouldNotReceive('execute');

    $payload = [
        'targetId' => (string) $this->stranger->getKey(),
        'sourceId' => ModelStub::ulid('third-record'),
    ];

    expect(fn (): JsonResponse => $this->controller->store(($this->request)($payload), $this->record))
        ->toThrow(AuthorizationException::class);
});

it('checks the delete right on the source before it merges anything', function (): void {
    $gate = GateSpy::allowing('merge', 'update');
    $this->mergeRecords->shouldNotReceive('execute');

    expect(fn (): JsonResponse => $this->controller->store(($this->request)(($this->pair)()), $this->record))
        ->toThrow(AuthorizationException::class)
        ->and($gate->abilities())->toBe(['merge', 'merge', 'merge', 'update', 'delete']);
});

it('refuses an override value that names neither side of the merge', function (): void {
    GateSpy::allowing('merge', 'update', 'delete');
    $this->mergeRecords->shouldNotReceive('execute');

    $request = ($this->request)(($this->pair)(['overrides' => ['email' => 'whatever']]));

    expect(fn (): JsonResponse => $this->controller->store($request, $this->record))
        ->toThrow(ValidationException::class);
});

it('hands the merge action the pair and the overrides the caller sent', function (): void {
    GateSpy::allowing('merge', 'update', 'delete');

    $received = null;

    $this->mergeRecords->shouldReceive('execute')
        ->once()
        ->andReturnUsing(function (MergeRequestData $data) use (&$received): RecordMerge {
            $received = $data;

            return ModelStub::make(RecordMerge::class, [
                'id' => ModelStub::ulid('merge'),
                'tenant_id' => $this->tenant->getKey(),
                'target_record_id' => (string) $this->record->getKey(),
                'source_record_id' => (string) $this->record->getKey(),
            ]);
        });

    $response = $this->controller->store(
        ($this->request)(($this->pair)(['overrides' => ['email' => 'source'], 'reason' => 'Dublette'])),
        $this->record,
    );

    expect($response->getStatusCode())->toBe(201)
        ->and($received?->targetId)->toBe((string) $this->record->getKey())
        ->and($received?->overrides)->toBe(['email' => 'source'])
        ->and($received?->reason)->toBe('Dublette');
});

it('answers a refused merge with its blockers instead of an error page', function (): void {
    GateSpy::allowing('merge', 'update', 'delete');

    $this->mergeRecords->shouldReceive('execute')
        ->once()
        ->andThrow(new MergeRefusedException([]));

    $response = $this->controller->store(($this->request)(($this->pair)()), $this->record);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toHaveKeys(['message', 'blockers']);
});
