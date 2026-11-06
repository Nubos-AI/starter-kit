<?php

declare(strict_types=1);

use App\Actions\Reports\ExportReportAction;
use App\Enums\Export\ExportFormat;
use App\Http\Controllers\Reports\ReportExportsController;
use App\Models\ExportJob;
use App\Models\Report;
use App\Models\User;
use App\Support\Export\ReportExportRunner;
use App\Support\Export\TemporalReportExportDispatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->report = ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('report'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('owner'),
        'object_type_id' => ModelStub::ulid('deals'),
        'name' => 'Pipeline',
    ]);

    $this->exportReport = Mockery::mock(ExportReportAction::class);
    $this->dispatcher = Mockery::mock(TemporalReportExportDispatcher::class);

    $this->controller = new ReportExportsController($this->exportReport, $this->dispatcher);

    /** @var callable():User */
    $this->viewer = fn (): User => AccessContext::actAs(AccessContext::user($this->tenant));

    /** @var callable(?User, array<string, mixed>):Request */
    $this->requestOf = static function (?User $user, array $payload = []): Request {
        $request = Request::create('/reports/exports', 'POST', $payload);
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    /** @var callable(array<string, mixed>):ExportJob */
    $this->job = fn (array $overrides = []): ExportJob => ModelStub::make(ExportJob::class, [
        'id' => ModelStub::ulid('export-job'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('deals'),
        'user_id' => ModelStub::ulid('user'),
        'format' => ExportFormat::Csv->value,
        'scope' => ['kind' => 'report', 'reportId' => (string) $this->report->getKey()],
        'result_path' => null,
        ...$overrides,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses an export to a caller who may not view the report and starts no workflow', function (): void {
    GateSpy::allowing();

    $this->exportReport->shouldReceive('execute')->never();
    $this->dispatcher->shouldReceive('start')->never();

    expect(fn (): JsonResponse => $this->controller->store(($this->requestOf)(($this->viewer)(), ['format' => 'csv']), $this->report))
        ->toThrow(AuthorizationException::class);
});

it('accepts an export for a viewer of the report and starts the workflow for the created job', function (): void {
    GateSpy::allowing('view');

    $user = ($this->viewer)();
    $job = ($this->job)(['user_id' => $user->getKey()]);

    $this->exportReport->shouldReceive('execute')->once()->andReturn($job);
    $this->dispatcher->shouldReceive('start')->once()->with($job);

    $response = $this->controller->store(($this->requestOf)($user, ['format' => 'csv']), $this->report);

    expect($response->getStatusCode())->toBe(Response::HTTP_ACCEPTED)
        ->and($response->getData(true))->toBe(['exportJobId' => (string) $job->getKey()]);
});

it('rejects a format outside csv and xlsx before an export job exists', function (): void {
    $action = new ExportReportAction;

    $user = ($this->viewer)();

    foreach (['json', 'pdf', ''] as $format) {
        try {
            $action->execute($this->report, $user, ['format' => $format]);
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toBe(['format']);

            continue;
        }

        throw new RuntimeException("The format [{$format}] was accepted.");
    }
});

it('scopes a created export job to the report, the tenant and the acting user', function (): void {
    $action = new ExportReportAction;
    $user = ($this->viewer)();

    $shape = QueryShape::attemptedBy(
        fn (): ExportJob => $action->execute($this->report, $user, ['format' => 'csv']),
    );

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('insert into "export_jobs"')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding((string) $user->getKey()))->toBeTrue()
        ->and($shape->hasBinding('running'))->toBeTrue()
        ->and($shape->bindings)->toContain(json_encode(['kind' => 'report', 'reportId' => (string) $this->report->getKey()]));
});

it('answers a download before the file exists with a conflict instead of a stream', function (): void {
    GateSpy::allowing('view');

    $user = ($this->viewer)();
    $job = ($this->job)(['user_id' => $user->getKey()]);

    $response = $this->controller->download(($this->requestOf)($user), $this->report, $job);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(Response::HTTP_CONFLICT);
});

it('refuses to hand out an export job that does not belong to the caller and the report', function (array $overrides): void {
    GateSpy::allowing('view');

    $user = ($this->viewer)();
    $job = ($this->job)([
        'user_id' => $user->getKey(),
        'result_path' => 'exports/x.csv',
        ...$overrides,
    ]);

    expect(fn (): StreamedResponse|JsonResponse => $this->controller->download(($this->requestOf)($user), $this->report, $job))
        ->toThrow(AuthorizationException::class);
})->with([
    'started by another user' => [['user_id' => ModelStub::ulid('somebody-else')]],
    'of another tenant' => [['tenant_id' => ModelStub::ulid('other-tenant')]],
    'scoped to a different report' => [['scope' => ['kind' => 'report', 'reportId' => ModelStub::ulid('another-report')]]],
    'a record export job' => [['scope' => ['kind' => 'records', 'reportId' => ModelStub::ulid('report')]]],
]);

it('refuses a download to a caller who may not view the report at all', function (): void {
    GateSpy::allowing();

    $user = ($this->viewer)();
    $job = ($this->job)(['user_id' => $user->getKey(), 'result_path' => 'exports/x.csv']);

    expect(fn (): StreamedResponse|JsonResponse => $this->controller->download(($this->requestOf)($user), $this->report, $job))
        ->toThrow(AuthorizationException::class);
});

it('loads the object type of a report export for its tenant although the worker has no tenant bound', function (): void {
    AccessContext::forgetTenant();

    $tenantId = (string) $this->report->tenant_id;
    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => match (true) {
            str_contains($sql, 'from "export_jobs"') => [[
                'id' => ModelStub::ulid('export-job'),
                'tenant_id' => $tenantId,
                'format' => ExportFormat::Csv->value,
                'scope' => json_encode(['reportId' => ModelStub::ulid('report')]),
            ]],
            str_contains($sql, 'from "reports"') => [[
                'id' => ModelStub::ulid('report'),
                'tenant_id' => $tenantId,
                'owner_id' => ModelStub::ulid('owner'),
                'object_type_id' => ModelStub::ulid('deals'),
            ]],
            default => [],
        },
        static fn (): int => 1,
    );

    try {
        expect(fn () => app(ReportExportRunner::class)->run($tenantId, ModelStub::ulid('user'), ModelStub::ulid('export-job')))
            ->toThrow(ModelNotFoundException::class);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $lookup = collect($connection->queries)->first(static fn (array $query): bool => str_contains($query['sql'], 'from "object_types"'));
    $shape = new QueryShape((string) ($lookup['sql'] ?? ''), $lookup['bindings'] ?? []);

    expect($shape->blocksEveryRow())->toBeFalse()
        ->and($shape->isScopedToTenant('object_types', $tenantId))->toBeTrue()
        ->and($shape->isKeyedTo('object_types', ModelStub::ulid('deals')))->toBeTrue();
});
