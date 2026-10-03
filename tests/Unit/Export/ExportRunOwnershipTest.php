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

it('refuses the file of an export another user started', function (): void {
    expect(fn (): StreamedResponse => $this->controller->download(
        ($this->requestOf)($this->colleague),
        $this->objectType,
        ($this->jobOf)((string) $this->owner->getKey()),
    ))->toThrow(AuthorizationException::class);
});

it('refuses the file of an export that belongs to another object type', function (): void {
    $job = ($this->jobOf)((string) $this->owner->getKey());
    $job->object_type_id = ModelStub::ulid('contacts');

    expect(fn (): StreamedResponse => $this->controller->download(
        ($this->requestOf)($this->owner),
        $this->objectType,
        $job,
    ))->toThrow(AuthorizationException::class);
});

it('refuses the file of an export of another tenant', function (): void {
    $job = ($this->jobOf)((string) $this->owner->getKey());
    $job->tenant_id = ModelStub::ulid('other-tenant');

    expect(fn (): StreamedResponse => $this->controller->download(
        ($this->requestOf)($this->owner),
        $this->objectType,
        $job,
    ))->toThrow(AuthorizationException::class);
});

it('refuses an export whose file was never written', function (): void {
    expect(fn (): StreamedResponse => $this->controller->download(
        ($this->requestOf)($this->owner),
        $this->objectType,
        ($this->jobOf)((string) $this->owner->getKey(), ['mode' => 'whole-type'], null),
    ))->toThrow(AuthorizationException::class);
});

it('keeps a report export out of the record export download route', function (): void {
    expect(fn (): StreamedResponse => $this->controller->download(
        ($this->requestOf)($this->owner),
        $this->objectType,
        ($this->jobOf)((string) $this->owner->getKey(), ['kind' => 'report']),
    ))->toThrow(AuthorizationException::class);
});

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

it('guards every export endpoint with the export permission of the object type and the export capability', function (): void {
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
            ->and($route->hasDeclaredMiddleware('capability:export'))->toBeTrue();
    }
});
