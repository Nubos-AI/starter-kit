<?php

declare(strict_types=1);

use App\Contracts\Engine\BulkDispatcherInterface;
use App\Http\Controllers\Engine\BulkActionsController;
use App\Models\User;
use App\Support\Engine\BulkBatchReport;
use App\Support\Engine\RecordSelectionResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    URL::defaults(['activeTeam' => 'personal']);

    $this->disk = (string) config('engine.bulk.export_disk', config('filesystems.default'));
    Storage::fake($this->disk);

    $this->tenant = AccessContext::tenant();
    $this->owner = AccessContext::user($this->tenant, [], 'bulk-export-owner');
    $this->colleague = AccessContext::user($this->tenant, [], 'bulk-export-colleague');

    $this->batchId = 'f1d7a2c4-1b2e-4f3a-9c8d-0a1b2c3d4e5f';
    $this->exportPath = 'exports/bulk-export.csv';

    Storage::disk($this->disk)->put($this->exportPath, "titel\nExport A\n");

    BulkBatchReport::initProgress($this->batchId, (string) $this->tenant->getKey(), (string) $this->owner->getKey(), 1);
    BulkBatchReport::markFinished($this->batchId);
    BulkBatchReport::setDownloadPath($this->batchId, $this->exportPath);

    $this->controller = new BulkActionsController(
        Mockery::mock(RecordSelectionResolver::class),
        Mockery::mock(BulkDispatcherInterface::class),
    );

    /** @var callable(?User):Request */
    $this->requestOf = function (?User $user): Request {
        $request = Request::create('/probe', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('offers the download only to the caller who started the batch', function (): void {
    $response = $this->controller->show(($this->requestOf)($this->owner), $this->batchId);

    /** @var array<string, mixed> $payload */
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($payload['downloadUrl'])->toBe(route('engine.batches.download', ['batchId' => $this->batchId]))
        ->and($payload['finished'])->toBeTrue();
});

it('hides the batch of another user behind a not found instead of a forbidden', function (): void {
    $shown = $this->controller->show(($this->requestOf)($this->colleague), $this->batchId);
    $downloaded = $this->controller->download(($this->requestOf)($this->colleague), $this->batchId);

    expect($shown->getStatusCode())->toBe(Response::HTTP_NOT_FOUND)
        ->and($downloaded)->toBeInstanceOf(JsonResponse::class)
        ->and($downloaded->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

it('hides the batch of another tenant from a caller of the same name', function (): void {
    $stranger = AccessContext::user(AccessContext::tenant('other-tenant'), [], 'bulk-export-owner');

    expect($this->controller->show(($this->requestOf)($stranger), $this->batchId)->getStatusCode())
        ->toBe(Response::HTTP_NOT_FOUND);
});

it('reports an unknown batch as not found', function (): void {
    expect($this->controller->show(($this->requestOf)($this->owner), 'a0000000-0000-4000-8000-000000000000')->getStatusCode())
        ->toBe(Response::HTTP_NOT_FOUND);
});

it('refuses to hand the export to a caller it cannot identify', function (): void {
    expect(fn (): mixed => $this->controller->download(($this->requestOf)(null), $this->batchId))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): mixed => $this->controller->show(($this->requestOf)(null), $this->batchId))
        ->toThrow(AuthorizationException::class);
});

it('streams the stored export back to its owner', function (): void {
    $response = $this->controller->download(($this->requestOf)($this->owner), $this->batchId);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($response->headers->get('content-disposition'))->toContain('bulk-export.csv');
});

it('keeps the batch routes behind a login and behind a well formed batch id', function (): void {
    $download = RouteShape::named('engine.batches.download');
    $show = RouteShape::named('engine.batches.show');

    expect($download->resolvedMiddleware())->toContain(Authenticate::class)
        ->and($show->resolvedMiddleware())->toContain(Authenticate::class)
        ->and($download->handledBy())->toContain('BulkActionsController@download')
        ->and($download->uri())->toEndWith('engine/batches/{batchId}/download');
});
