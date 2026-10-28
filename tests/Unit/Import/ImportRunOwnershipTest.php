<?php

declare(strict_types=1);

use App\Actions\Import\PrepareRecordImportAction;
use App\Contracts\Import\ImportDispatcherInterface;
use App\Http\Controllers\Import\ImportExecutionsController;
use App\Models\ImportJob;
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

    $this->owner = AccessContext::user($this->tenant, [], 'run-owner');
    $this->colleague = AccessContext::user($this->tenant, [], 'colleague');

    $this->controller = new ImportExecutionsController(
        Mockery::mock(PrepareRecordImportAction::class),
        Mockery::mock(ImportDispatcherInterface::class),
    );

    /** @var callable(User):Request */
    $this->requestOf = static function (User $user): Request {
        $request = Request::create('/probe', 'GET');
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };

    /** @var callable(string, string, ?string):ImportJob */
    $this->jobOf = fn (string $ownerId, string $reportPath = 'imports/errors.csv'): ImportJob => ModelStub::make(ImportJob::class, [
        'id' => ModelStub::ulid('import-job'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
        'user_id' => $ownerId,
        'source_disk' => 'local',
        'error_report_path' => $reportPath,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('binds the status of an import run to the tenant, the object type and the user who started it', function (): void {
    $shape = QueryShape::attemptedBy(fn (): JsonResponse => $this->controller->status(
        ($this->requestOf)($this->colleague),
        $this->objectType,
        ModelStub::ulid('import-job'),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('import_jobs'))->toBeTrue()
        ->and($shape->sql)->toContain('"user_id" = ?')
        ->and($shape->hasBinding((string) $this->colleague->getKey()))->toBeTrue()
        ->and($shape->hasBinding($this->tenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('import_jobs', ModelStub::ulid('import-job')))->toBeTrue();
});

it('lists only the import runs the requesting user started', function (): void {
    $shape = QueryShape::attemptedBy(fn (): JsonResponse => $this->controller->history(
        ($this->requestOf)($this->colleague),
        $this->objectType,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('import_jobs'))->toBeTrue()
        ->and($shape->sql)->toContain('"user_id" = ?')
        ->and($shape->hasBinding((string) $this->colleague->getKey()))->toBeTrue();
});

it('refuses the error report of an import run another user started', function (): void {
    $job = ($this->jobOf)((string) $this->owner->getKey());

    expect(fn (): StreamedResponse => $this->controller->errorReport(
        ($this->requestOf)($this->colleague),
        $this->objectType,
        $job,
    ))->toThrow(AuthorizationException::class);
});

it('refuses the error report of an import run that belongs to another object type', function (): void {
    $job = ($this->jobOf)((string) $this->owner->getKey());
    $job->object_type_id = ModelStub::ulid('contacts');

    expect(fn (): StreamedResponse => $this->controller->errorReport(
        ($this->requestOf)($this->owner),
        $this->objectType,
        $job,
    ))->toThrow(AuthorizationException::class);
});

it('refuses the error report of an import run of another tenant', function (): void {
    $job = ($this->jobOf)((string) $this->owner->getKey());
    $job->tenant_id = ModelStub::ulid('other-tenant');

    expect(fn (): StreamedResponse => $this->controller->errorReport(
        ($this->requestOf)($this->owner),
        $this->objectType,
        $job,
    ))->toThrow(AuthorizationException::class);
});

it('refuses an error report that was never written instead of streaming an empty file', function (): void {
    $job = ($this->jobOf)((string) $this->owner->getKey());
    $job->error_report_path = null;

    expect(fn (): StreamedResponse => $this->controller->errorReport(
        ($this->requestOf)($this->owner),
        $this->objectType,
        $job,
    ))->toThrow(AuthorizationException::class);
});

it('refuses every caller who is not signed in on status, history and the error report', function (): void {
    $anonymous = Request::create('/probe', 'GET');
    $anonymous->setUserResolver(static fn (): ?User => null);

    expect(fn (): JsonResponse => $this->controller->status($anonymous, $this->objectType, ModelStub::ulid('import-job')))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): JsonResponse => $this->controller->history($anonymous, $this->objectType))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): StreamedResponse => $this->controller->errorReport($anonymous, $this->objectType, ($this->jobOf)((string) $this->owner->getKey())))
        ->toThrow(AuthorizationException::class);
});

it('guards every import endpoint with the import permission of the object type and the import capability', function (): void {
    $names = [
        'engine.import.upload',
        'engine.import.preview',
        'engine.import.execute',
        'engine.import.history',
        'engine.import.status',
        'engine.import.error-report',
        'engine.import.presets.index',
        'engine.import.presets.store',
        'engine.import.presets.update',
        'engine.import.presets.destroy',
    ];

    foreach ($names as $name) {
        $route = RouteShape::named($name);

        expect($route->hasDeclaredMiddleware('permission:{objectType}.import'))->toBeTrue()
            ->and($route->hasDeclaredMiddleware('capability:import'))->toBeTrue();
    }
});
