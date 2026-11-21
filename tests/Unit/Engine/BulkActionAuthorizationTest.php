<?php

declare(strict_types=1);

use App\Contracts\Engine\BulkDispatcherInterface;
use App\DTOs\Engine\BulkActionData;
use App\Enums\Engine\ObjectTypeCapability;
use App\Http\Controllers\Engine\BulkActionsController;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\ObjectTypeCapabilityGuard;
use App\Support\Engine\RecordSelectionResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "tenant_settings"')
        ? [['id' => ModelStub::ulid('bulk-settings'), 'tenant_id' => (string) $this->tenant->getKey(), 'bulk_grouping_threshold' => 50]]
        : []);
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant, [], 'bulk-actor'));

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('bulk-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deal',
    ]);

    $this->selectionResolver = Mockery::mock(RecordSelectionResolver::class);
    $this->dispatcher = Mockery::mock(BulkDispatcherInterface::class);
    $this->capabilities = Mockery::mock(ObjectTypeCapabilityGuard::class);

    $this->controller = new BulkActionsController($this->selectionResolver, $this->dispatcher, $this->capabilities);

    /** @var callable(string):Request */
    $this->requestFor = function (string $action): Request {
        $request = Request::create('/probe', 'POST', [
            'action' => $action,
            'selection' => ['mode' => 'visible', 'includedIds' => [ModelStub::ulid('bulk-record')]],
        ]);
        $request->setUserResolver(fn (): User => $this->user);

        return $request;
    };

    /** @var callable():void */
    $this->expectSelection = function (): void {
        $this->selectionResolver->shouldReceive('selectionRules')->andReturn([
            'selection' => ['required', 'array'],
            'selection.mode' => ['required', 'string'],
            'selection.includedIds' => ['array'],
        ]);
        $this->selectionResolver->shouldReceive('filterScope')->andReturn([
            'fields' => collect(),
            'readable' => collect(),
            'expressions' => [],
        ]);
        $this->selectionResolver->shouldReceive('scopedQuery')->andReturn(CustomRecord::query());
        $this->selectionResolver->shouldReceive('chunkedIds')->andReturn([[ModelStub::ulid('bulk-record')]]);
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses a csv export to a role that may only view the records and never starts the batch', function (): void {
    AccessContext::grant('deal.view');
    ($this->expectSelection)();
    $this->capabilities->shouldReceive('assertSupports')->never();
    $this->dispatcher->shouldReceive('start')->never();

    expect(fn (): mixed => $this->controller->store(($this->requestFor)('export-csv'), $this->objectType))
        ->toThrow(AuthorizationException::class);
});

it('refuses a csv export when the object type has the export capability switched off', function (): void {
    AccessContext::grant('deal.view', 'deal.export');
    ($this->expectSelection)();
    $this->capabilities->shouldReceive('assertSupports')
        ->once()
        ->with($this->objectType, ObjectTypeCapability::Export)
        ->andThrow(new NotFoundHttpException);
    $this->dispatcher->shouldReceive('start')->never();

    expect(fn (): mixed => $this->controller->store(($this->requestFor)('export-csv'), $this->objectType))
        ->toThrow(NotFoundHttpException::class);
});

it('refuses a csv export to a role that may export but not view the records and never starts the batch', function (): void {
    AccessContext::grant('deal.export');
    ($this->expectSelection)();
    $this->capabilities->shouldReceive('assertSupports')->never();
    $this->dispatcher->shouldReceive('start')->never();

    expect(fn (): mixed => $this->controller->store(($this->requestFor)('export-csv'), $this->objectType))
        ->toThrow(AuthorizationException::class);
});

it('starts a csv export for a role holding the view and export rights on an exportable object type', function (): void {
    AccessContext::grant('deal.view', 'deal.export');
    ($this->expectSelection)();
    $this->capabilities->shouldReceive('assertSupports')->once()->with($this->objectType, ObjectTypeCapability::Export);
    $this->dispatcher->shouldReceive('start')
        ->once()
        ->with(Mockery::on(static fn (BulkActionData $data): bool => $data->action === 'export-csv'));

    expect($this->controller->store(($this->requestFor)('export-csv'), $this->objectType)->getStatusCode())
        ->toBe(Response::HTTP_ACCEPTED);
});

it('rejects a bulk restore as an unknown action and never selects or starts anything', function (): void {
    AccessContext::grant('deal.view', 'deal.update', 'deal.delete', 'deal.export');
    $this->selectionResolver->shouldReceive('selectionRules')->andReturn([
        'selection' => ['required', 'array'],
        'selection.mode' => ['required', 'string'],
        'selection.includedIds' => ['array'],
    ]);
    $this->selectionResolver->shouldReceive('scopedQuery')->never();
    $this->dispatcher->shouldReceive('start')->never();

    $response = $this->controller->store(($this->requestFor)('restore'), $this->objectType);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->and($response->getData(true)['errors'])->toHaveKey('action');
});

it('selects the records of a bulk delete within the segment the acting user was looking at', function (): void {
    AccessContext::grant('deal.delete');
    $this->selectionResolver->shouldReceive('selectionRules')
        ->andReturn(app(RecordSelectionResolver::class)->selectionRules(withSortModel: true));
    $this->selectionResolver->shouldReceive('filterScope')->andReturn(['fields' => collect(), 'readable' => collect(), 'expressions' => []]);
    $this->selectionResolver->shouldReceive('scopedQuery')
        ->once()
        ->withArgs(fn (ObjectType $objectType, array $selection, mixed $fields, array $expressions, mixed $readable, User $viewer): bool => $viewer === $this->user
            && $selection['mode'] === 'all-matching'
            && $selection['segmentId'] === ModelStub::ulid('open-deals'))
        ->andReturn(CustomRecord::query());
    $this->selectionResolver->shouldReceive('chunkedIds')->andReturn([]);
    $this->dispatcher->shouldReceive('start')->once();

    $request = Request::create('/probe', 'POST', [
        'action' => 'soft-delete',
        'selection' => ['mode' => 'all-matching', 'segmentId' => ModelStub::ulid('open-deals'), 'filterModel' => []],
    ]);
    $request->setUserResolver(fn (): User => $this->user);

    expect($this->controller->store($request, $this->objectType)->getStatusCode())->toBe(Response::HTTP_ACCEPTED);
});
