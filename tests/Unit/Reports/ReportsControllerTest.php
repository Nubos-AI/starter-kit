<?php

declare(strict_types=1);

use App\Actions\Reports\BulkDeleteReportsAction;
use App\Actions\Reports\CreateReportAction;
use App\Actions\Reports\DeleteReportAction;
use App\Actions\Reports\UpdateReportAction;
use App\Http\Controllers\Reports\ReportsController;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Support\Reports\ReportEditorPresenter;
use App\Support\Teams\TeamSegment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Response;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    URL::defaults([TeamSegment::key() => 'current']);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('deals'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
        'name' => 'Deals',
    ]);

    $this->createReport = Mockery::mock(CreateReportAction::class);
    $this->updateReport = Mockery::mock(UpdateReportAction::class);
    $this->deleteReport = Mockery::mock(DeleteReportAction::class);
    $this->bulkDeleteReports = Mockery::mock(BulkDeleteReportsAction::class);
    $this->editorPresenter = Mockery::mock(ReportEditorPresenter::class);

    $this->controller = new ReportsController(
        $this->createReport,
        $this->updateReport,
        $this->deleteReport,
        $this->bulkDeleteReports,
        $this->editorPresenter,
    );

    $this->reportId = ModelStub::ulid('report');

    /** @var callable(?User, array<string, mixed>):Request */
    $this->requestOf = static function (?User $user, array $payload = []): Request {
        $request = Request::create('/reports', 'POST', $payload);
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    /** @var callable():User */
    $this->viewer = fn (): User => AccessContext::actAs(AccessContext::user($this->tenant));
});

afterEach(function (): void {
    URL::defaults([TeamSegment::key() => null]);
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses the report list to a caller the gate turns away and never queries a report', function (): void {
    GateSpy::allowing();

    $reached = QueryShape::attemptedBy(function (): void {
        try {
            $this->controller->index(($this->requestOf)(($this->viewer)()));
        } catch (AuthorizationException) {
            return;
        }
    });

    expect(fn (): Response => $this->controller->index(($this->requestOf)(($this->viewer)())))
        ->toThrow(AuthorizationException::class)
        ->and($reached)->toBeNull();
});

it('narrows the report list to the tenant of the caller', function (): void {
    GateSpy::allowing('viewAny');

    $user = ($this->viewer)();

    $shape = QueryShape::attemptedBy(fn (): Response => $this->controller->index(($this->requestOf)($user)));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('reports'))->toBeTrue()
        ->and($shape->isScopedToTenant('reports', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('refuses to store a report before the creating action is ever called', function (): void {
    GateSpy::allowing();

    $this->createReport->shouldReceive('execute')->never();

    expect(fn (): RedirectResponse => $this->controller->store(($this->requestOf)(($this->viewer)(), ['name' => 'x'])))
        ->toThrow(AuthorizationException::class);
});

it('hands the raw input of the request to the creating action', function (): void {
    GateSpy::allowing('create');

    $user = ($this->viewer)();
    $payload = ['name' => 'Pipeline', 'chart_type' => 'bar'];

    $this->createReport->shouldReceive('execute')
        ->once()
        ->withArgs(static fn (User $actor, array $input): bool => $actor === $user
            && $input['name'] === 'Pipeline'
            && $input['chart_type'] === 'bar');

    expect($this->controller->store(($this->requestOf)($user, $payload)))
        ->toBeInstanceOf(RedirectResponse::class);
});

it('refuses a bulk deletion before the bulk action is ever called', function (): void {
    GateSpy::allowing();

    $this->bulkDeleteReports->shouldReceive('execute')->never();

    expect(fn (): RedirectResponse => $this->controller->bulkDestroy(($this->requestOf)(($this->viewer)(), ['ids' => [$this->reportId]])))
        ->toThrow(AuthorizationException::class);
});

it('resolves a report for editing inside the tenant of the caller and never by id alone', function (): void {
    GateSpy::allowing('update');

    $user = ($this->viewer)();

    $shape = QueryShape::attemptedBy(fn (): Response => $this->controller->edit(($this->requestOf)($user), $this->reportId));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('reports'))->toBeTrue()
        ->and($shape->isScopedToTenant('reports', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('reports', $this->reportId))->toBeTrue();
});

it('refuses an unauthenticated caller before touching the gate', function (): void {
    GateSpy::allowing('viewAny', 'create', 'update', 'delete');

    expect(fn (): Response => $this->controller->index(($this->requestOf)(null)))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): RedirectResponse => $this->controller->store(($this->requestOf)(null)))
        ->toThrow(AuthorizationException::class);
});

it('refuses to update a report the gate withholds and never calls the updating action', function (): void {
    $report = ModelStub::make(Report::class, [
        'id' => $this->reportId,
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('owner'),
        'object_type_id' => $this->objectType->getKey(),
    ]);

    $this->updateReport->shouldReceive('execute')->never();
    $this->deleteReport->shouldReceive('execute')->never();

    $spy = GateSpy::allowing();

    $user = ($this->viewer)();

    expect(fn (): bool => $user->can('update', $report))->not->toThrow(Exception::class)
        ->and($user->can('update', $report))->toBeFalse()
        ->and($spy->wasAskedFor('update'))->toBeTrue();
});
