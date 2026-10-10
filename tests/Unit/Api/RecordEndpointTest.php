<?php

declare(strict_types=1);

use App\Actions\Engine\CreateRecordAction;
use App\Actions\Engine\DeleteRecordAction;
use App\Actions\Engine\UpdateRecordAction;
use App\Http\Controllers\Api\V1\Records\RecordsController;
use App\Http\Resources\Api\JsonApiRecordCollection;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Api\ApiQueryCompiler;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
        'key' => 'companies',
    ]);

    $registry = new class($this->objectType) extends ObjectTypeRegistry
    {
        public function __construct(private readonly ObjectType $canned) {}

        public function bySlug(string $slug): ObjectType
        {
            return $this->canned;
        }
    };

    $this->createRecord = Mockery::mock(CreateRecordAction::class);
    $this->updateRecord = Mockery::mock(UpdateRecordAction::class);
    $this->deleteRecord = Mockery::mock(DeleteRecordAction::class);

    $this->controller = new RecordsController(
        $registry,
        app(ApiQueryCompiler::class),
        $this->createRecord,
        $this->updateRecord,
        $this->deleteRecord,
    );

    /** @var callable(?User, array<string, mixed>, string):Request */
    $this->requestOf = static function (?User $user, array $payload = [], string $method = 'GET'): Request {
        $request = Request::create('/api/v1/companies', $method, $payload);
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('serves an empty index to a caller without the view permission of the object type', function (): void {
    $resolver = AccessContext::grant('contacts.view');
    $request = ($this->requestOf)(AccessContext::user($this->tenant));

    $shape = QueryShape::attemptedBy(fn (): JsonApiRecordCollection|JsonResponse => $this->controller->index($request, 'companies'));

    expect($shape)->not->toBeNull()
        ->and($shape->blocksEveryRow())->toBeTrue()
        ->and($resolver->askedFor)->toContain('companies.view');
});

it('serves an index scoped to the tenant and the object type to an entitled caller', function (): void {
    AccessContext::grant('companies.view');
    $request = ($this->requestOf)(AccessContext::user($this->tenant));

    $shape = QueryShape::attemptedBy(fn (): JsonApiRecordCollection|JsonResponse => $this->controller->index($request, 'companies'));

    expect($shape)->not->toBeNull()
        ->and($shape->blocksEveryRow())->toBeFalse()
        ->and($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->bindings)->toContain((string) $this->objectType->getKey());
});

it('serves an empty index to a caller the request never authenticated', function (): void {
    AccessContext::grant('companies.view');
    $request = ($this->requestOf)(null);

    $shape = QueryShape::attemptedBy(fn (): JsonApiRecordCollection|JsonResponse => $this->controller->index($request, 'companies'));

    expect($shape)->not->toBeNull()
        ->and($shape->blocksEveryRow())->toBeTrue();
});

it('refuses to create a record without the create permission of the object type', function (): void {
    $resolver = AccessContext::grant('companies.view');
    $this->createRecord->shouldNotReceive('execute');

    $request = ($this->requestOf)(AccessContext::user($this->tenant), ['data' => ['attributes' => []]], 'POST');

    expect(fn (): JsonResponse => $this->controller->store($request, 'companies'))
        ->toThrow(AuthorizationException::class)
        ->and($resolver->askedFor)->toBe(['companies.create']);
});

it('refuses to create a record for an unauthenticated caller', function (): void {
    AccessContext::grant('companies.create');
    $this->createRecord->shouldNotReceive('execute');

    $request = ($this->requestOf)(null, ['data' => ['attributes' => []]], 'POST');

    expect(fn (): JsonResponse => $this->controller->store($request, 'companies'))
        ->toThrow(AuthorizationException::class);
});

it('resolves a record inside its own object type and inside the tenant before the policy is consulted', function (): void {
    AccessContext::grant('companies.view');
    $identifier = ModelStub::ulid('company-record');
    $request = ($this->requestOf)(AccessContext::user($this->tenant));

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->controller->show($request, 'companies', $identifier));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->bindings)->toContain((string) $this->objectType->getKey())
        ->and($shape->bindings)->toContain($identifier);
});

it('resolves a non ulid identifier as a record number and never as a primary key', function (): void {
    AccessContext::grant('companies.view');
    $request = ($this->requestOf)(AccessContext::user($this->tenant));

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->controller->show($request, 'companies', 'C-1042'));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"record_number" = ?')
        ->and($shape->bindings)->toContain('C-1042');
});

it('resolves the record of a write verb the same way before it changes anything', function (string $method): void {
    AccessContext::grant('companies.view', 'companies.update', 'companies.delete');
    $identifier = ModelStub::ulid('company-record');
    $request = ($this->requestOf)(AccessContext::user($this->tenant), [], 'PATCH');

    $this->updateRecord->shouldNotReceive('execute');
    $this->deleteRecord->shouldNotReceive('execute');

    $shape = QueryShape::attemptedBy(fn (): mixed => $method === 'destroy'
        ? $this->controller->destroy('companies', $identifier)
        : $this->controller->update($request, 'companies', $identifier));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->bindings)->toContain($identifier);
})->with(['update', 'destroy']);

it('hides a soft deleted record from every record endpoint', function (): void {
    AccessContext::grant('companies.view');
    $request = ($this->requestOf)(AccessContext::user($this->tenant));

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->controller->show($request, 'companies', ModelStub::ulid('company-record')));

    expect($shape->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('clamps the page size and never lets a caller widen it past the cap', function (int $requested, int $expected): void {
    AccessContext::grant('companies.view');
    $request = ($this->requestOf)(AccessContext::user($this->tenant), ['page' => ['size' => $requested]]);

    $shape = QueryShape::attemptedBy(fn (): JsonApiRecordCollection|JsonResponse => $this->controller->index($request, 'companies'));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('limit '.($expected + 1));
})->with([
    'over the cap' => [5000, 100],
    'below the floor' => [0, 1],
    'inside the range' => [42, 42],
]);

it('defaults the page size when the caller names none', function (): void {
    AccessContext::grant('companies.view');
    $request = ($this->requestOf)(AccessContext::user($this->tenant));

    $shape = QueryShape::attemptedBy(fn (): JsonApiRecordCollection|JsonResponse => $this->controller->index($request, 'companies'));

    expect($shape->sql)->toContain('limit 26');
});

it('rejects a malformed filter parameter with 422 before any row is read', function (): void {
    AccessContext::grant('companies.view');
    $request = Request::create('/api/v1/companies?filter=oops', 'GET');
    $request->setUserResolver(fn (): User => AccessContext::user($this->tenant));

    $reachedTheDatabase = QueryShape::attemptedBy(fn (): mixed => $this->controller->index($request, 'companies'));
    $response = $this->controller->index($request, 'companies');

    expect($reachedTheDatabase)->toBeNull()
        ->and($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(422);
});
