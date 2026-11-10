<?php

declare(strict_types=1);

use App\Contracts\Engine\BulkDispatcherInterface;
use App\Http\Controllers\Engine\BulkActionsController;
use App\Models\User;
use App\Support\Engine\BulkBatchReport;
use App\Support\Engine\ObjectTypeCapabilityGuard;
use App\Support\Engine\RecordSelectionResolver;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('bulk-progress-tenant');
    $this->owner = AccessContext::user($this->tenant, [], 'bulk-progress-owner');
    $this->batchId = 'b2d7a2c4-1b2e-4f3a-9c8d-0a1b2c3d4e5f';

    BulkBatchReport::initProgress($this->batchId, (string) $this->tenant->getKey(), (string) $this->owner->getKey(), 3);

    $this->controller = new BulkActionsController(
        Mockery::mock(RecordSelectionResolver::class),
        Mockery::mock(BulkDispatcherInterface::class),
        Mockery::mock(ObjectTypeCapabilityGuard::class),
    );

    $this->request = Request::create('/probe', 'GET');
    $this->request->setUserResolver(fn (): User => $this->owner);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('tells the caller how many chunks of the batch failed', function (): void {
    BulkBatchReport::markChunkProcessed($this->batchId);
    BulkBatchReport::markChunksFailed($this->batchId, 2);
    BulkBatchReport::markFinished($this->batchId);

    $payload = $this->controller->show($this->request, $this->batchId)->getData(true);

    expect($payload['failedJobs'])->toBe(2)
        ->and($payload['processedJobs'])->toBe(1)
        ->and($payload['finished'])->toBeTrue();
});

it('reports no failed chunk while none failed', function (): void {
    expect($this->controller->show($this->request, $this->batchId)->getData(true)['failedJobs'])->toBe(0);
});
