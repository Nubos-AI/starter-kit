<?php

declare(strict_types=1);

use App\Http\Controllers\Engine\RecordLookupController;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\RecordSearchPredicate;
use App\Support\Engine\RecordTitleResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $titleResolver = Mockery::mock(RecordTitleResolver::class);
    $titleResolver->shouldReceive('titleKey')->andReturn('name');
    app()->instance(RecordTitleResolver::class, $titleResolver);

    $this->controller = new RecordLookupController(
        app(RecordSearchPredicate::class),
        $titleResolver,
    );

    /** @var callable(array<string, mixed>):Request */
    $this->request = function (array $query): Request {
        $request = Request::create('/probe', 'GET', $query);
        $user = AccessContext::user($this->tenant);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };

    /** @var callable(array<string, mixed>):?QueryShape */
    $this->attempt = fn (array $query): ?QueryShape => QueryShape::attemptedBy(
        fn (): JsonResponse => ($this->controller)(($this->request)($query), $this->objectType),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(RecordTitleResolver::class);
});

it('refuses a lookup for a caller who is not signed in', function (): void {
    $request = Request::create('/probe', 'GET');
    $request->setUserResolver(static fn (): ?User => null);

    expect(fn (): JsonResponse => ($this->controller)($request, $this->objectType))
        ->toThrow(AuthorizationException::class);
});

it('offers only live records of the object type inside the tenant', function (): void {
    $attempt = ($this->attempt)([]);

    expect($attempt)->not->toBeNull()
        ->and($attempt?->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->hasColumnCondition('custom_records', 'object_type_id'))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($attempt?->sql)->toContain('"merged_into_record_id" is null')
        ->and($attempt?->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('caps the options it reads and asks for one more to tell the caller there is more', function (): void {
    expect(($this->attempt)([])?->sql)->toContain('limit 26 offset 0');
});

it('hands out further options from the offset the caller asked for', function (): void {
    expect(($this->attempt)(['offset' => '25'])?->sql)->toContain('limit 26 offset 25');
});

it('refuses a negative offset instead of walking backwards', function (): void {
    expect(($this->attempt)(['offset' => '-10'])?->sql)->toContain('offset 0');
});

it('orders the options by recency with a stable tie breaker', function (): void {
    expect(($this->attempt)([])?->sql)->toContain('order by "updated_at" desc, "id" asc');
});

it('searches the title next to the record number and the external reference', function (): void {
    $attempt = ($this->attempt)(['q' => 'nubos']);

    expect($attempt?->sql)->toContain("((data->>'name') ILIKE ? OR record_number ILIKE ? OR external_reference_id ILIKE ?)")
        ->and($attempt?->bindings)->toContain('%nubos%');
});

it('keeps a leading space of the search term instead of throwing it away', function (): void {
    expect(($this->attempt)(['q' => ' 40'])?->bindings)->toContain('% 40%');
});

it('escapes the like wildcards a caller typed into the search term', function (): void {
    expect(($this->attempt)(['q' => '100%_x'])?->bindings)->toContain('%100\%\_x%');
});

it('searches nothing at all for a blank term', function (): void {
    expect(($this->attempt)(['q' => '   '])?->sql)->not->toContain('ILIKE');
});

it('guards the lookup route with the view permission of the object type', function (): void {
    $route = RouteShape::named('engine.records.lookup');

    expect($route->hasDeclaredMiddleware('permission:{objectType}.view'))->toBeTrue()
        ->and($route->handledBy())->toContain('RecordLookupController');
});
