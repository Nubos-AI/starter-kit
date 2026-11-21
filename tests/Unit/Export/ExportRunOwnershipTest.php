<?php

declare(strict_types=1);

use App\Actions\Export\StartRecordExportAction;
use App\Contracts\Export\ExportDispatcherInterface;
use App\Enums\Export\ExportFormat;
use App\Http\Controllers\Export\ExportsController;
use App\Models\ExportJob;
use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenantId,
        'slug' => 'companies',
    ]);

    $this->owner = AccessContext::user($this->tenant, [], 'export-owner');
    $this->colleague = AccessContext::user($this->tenant, [], 'colleague');

    $this->controller = new ExportsController(
        Mockery::mock(StartRecordExportAction::class),
        Mockery::mock(ExportDispatcherInterface::class),
    );

    /** @var callable(User):Request */
    $this->requestOf = static function (User $user): Request {
        $request = Request::create('/probe', 'GET');
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };

    /** @var callable(string, array<string, mixed>, ?string):ExportJob */
    $this->jobOf = fn (string $ownerId, array $scope = ['mode' => 'whole-type'], ?string $resultPath = 'exports/file.csv'): ExportJob => ModelStub::make(ExportJob::class, [
        'id' => ModelStub::ulid('export-job'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
        'user_id' => $ownerId,
        'format' => ExportFormat::Csv,
        'scope' => $scope,
        'result_path' => $resultPath,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('binds the status of an export run to the tenant, the object type and the user who started it', function (): void {
    $shape = QueryShape::attemptedBy(fn (): JsonResponse => $this->controller->status(
        ($this->requestOf)($this->colleague),
        $this->objectType,
        ModelStub::ulid('export-job'),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('export_jobs'))->toBeTrue()
        ->and($shape->sql)->toContain('"user_id" = ?')
        ->and($shape->hasBinding((string) $this->colleague->getKey()))->toBeTrue()
        ->and($shape->hasBinding($this->tenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue();
});

it('lists only the export runs the requesting user started', function (): void {
    $shape = QueryShape::attemptedBy(fn (): JsonResponse => $this->controller->history(
        ($this->requestOf)($this->colleague),
        $this->objectType,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('export_jobs'))->toBeTrue()
        ->and($shape->sql)->toContain('"user_id" = ?')
        ->and($shape->hasBinding((string) $this->colleague->getKey()))->toBeTrue();
});

it('refuses the file of an export the caller may not download', function (bool $isRequestedByColleague, array $scope, ?string $resultPath, array $reassigned): void {
    $job = ($this->jobOf)((string) $this->owner->getKey(), $scope, $resultPath);

    foreach ($reassigned as $attribute => $value) {
        $job->{$attribute} = $value;
    }

    expect(fn (): StreamedResponse => $this->controller->download(
        ($this->requestOf)($isRequestedByColleague ? $this->colleague : $this->owner),
        $this->objectType,
        $job,
    ))->toThrow(AuthorizationException::class);
})->with([
    'started by another user' => [true, ['mode' => 'whole-type'], 'exports/file.csv', []],
    'of another object type' => [false, ['mode' => 'whole-type'], 'exports/file.csv', ['object_type_id' => ModelStub::ulid('contacts')]],
    'of another tenant' => [false, ['mode' => 'whole-type'], 'exports/file.csv', ['tenant_id' => ModelStub::ulid('other-tenant')]],
    'file never written' => [false, ['mode' => 'whole-type'], null, []],
    'a report export on the record export route' => [false, ['kind' => 'report'], 'exports/file.csv', []],
]);

it('refuses every caller who is not signed in on status, history and download', function (): void {
    $anonymous = Request::create('/probe', 'GET');
    $anonymous->setUserResolver(static fn (): ?User => null);

    expect(fn (): JsonResponse => $this->controller->status($anonymous, $this->objectType, ModelStub::ulid('export-job')))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $this->controller->history($anonymous, $this->objectType))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): StreamedResponse => $this->controller->download($anonymous, $this->objectType, ($this->jobOf)((string) $this->owner->getKey())))
        ->toThrow(AuthorizationException::class);
});

it('guards every export endpoint with the export and the view permission of the object type and the export capability', function (): void {
    $names = [
        'engine.export.store',
        'engine.export.history',
        'engine.export.status',
        'engine.export.download',
        'engine.export.presets.index',
        'engine.export.presets.store',
        'engine.export.presets.update',
        'engine.export.presets.destroy',
    ];

    foreach ($names as $name) {
        $route = RouteShape::named($name);

        expect($route->hasDeclaredMiddleware('permission:{objectType}.export'))->toBeTrue()
            ->and($route->hasDeclaredMiddleware('permission:{objectType}.view'))->toBeTrue()
            ->and($route->hasDeclaredMiddleware('capability:export'))->toBeTrue();
    }
});

it('hands the export history out without the storage path of the file', function (): void {
    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "export_jobs"') ? [[
        'id' => ModelStub::ulid('export-job'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
        'user_id' => (string) $this->owner->getKey(),
        'status' => 'completed',
        'format' => 'csv',
        'scope' => '{"mode":"whole-type"}',
        'fields' => '["name"]',
        'result_path' => 'exports/tenant/export-job.csv',
        'row_count' => 12,
        'created_at' => '2026-11-01 08:00:00',
    ]] : []);

    try {
        $row = $this->controller->history(($this->requestOf)($this->owner), $this->objectType)->getData(true)['data'][0];
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($row)->toMatchArray([
        'id' => ModelStub::ulid('export-job'),
        'status' => 'completed',
        'format' => 'csv',
        'rowCount' => 12,
        'hasFile' => true,
    ])
        ->and(json_encode($row))->not->toContain('exports/tenant')
        ->and($row)->not->toHaveKeys(['result_path', 'resultPath', 'tenant_id', 'user_id']);
});
