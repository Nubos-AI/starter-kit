<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboards\DashboardWidgetResultsController;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Support\Dashboards\DashboardWidgetLocator;
use App\Support\Reports\WidgetResultResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::user($this->tenant, [], 'results-viewer');

    $this->dashboard = ModelStub::make(Dashboard::class, [
        'id' => ModelStub::ulid('results-dashboard'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('results-owner'),
        'name' => 'Board',
    ]);

    $this->widget = ModelStub::make(DashboardWidget::class, [
        'id' => ModelStub::ulid('results-widget'),
        'tenant_id' => $this->tenant->getKey(),
        'dashboard_id' => $this->dashboard->getKey(),
    ], ['dashboard' => $this->dashboard]);

    $this->resolver = Mockery::mock(WidgetResultResolver::class);
    $this->locator = Mockery::mock(DashboardWidgetLocator::class);

    $this->controller = fn (): DashboardWidgetResultsController => new DashboardWidgetResultsController(
        $this->resolver,
        $this->locator,
    );

    /** @var callable(?User):Request */
    $this->request = function (?User $user): Request {
        $request = Request::create('/dashboards/x/widget-results', 'POST');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('turns away a caller who is not signed in before it resolves anything', function (): void {
    $this->locator->shouldNotReceive('resolveDashboard');
    $this->resolver->shouldNotReceive('resolveMany');

    expect(fn (): JsonResponse => ($this->controller)()->index(($this->request)(null), 'anything'))
        ->toThrow(AuthorizationException::class);
});

it('refuses the whole tile list to a caller who may not read the dashboard', function (): void {
    $spy = GateSpy::allowing();
    $this->locator->shouldReceive('resolveDashboard')->andReturn($this->dashboard);
    $this->resolver->shouldNotReceive('resolveMany');

    expect(fn (): JsonResponse => ($this->controller)()->index(($this->request)($this->viewer), 'anything'))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('view'))->toBeTrue()
        ->and($spy->calls[0]['arguments'][0])->toBe($this->dashboard);
});

it('refuses a single tile to a caller who may not read the widget', function (): void {
    $spy = GateSpy::allowing();
    $this->locator->shouldReceive('resolveWidget')->andReturn($this->widget);
    $this->resolver->shouldNotReceive('resolve');

    expect(fn (): JsonResponse => ($this->controller)()->show(($this->request)($this->viewer), 'a', 'b'))
        ->toThrow(AuthorizationException::class)
        ->and($spy->calls[0]['arguments'][0])->toBe($this->widget);
});

it('reads the tiles of the permitted dashboard in the stored arrangement order', function (): void {
    GateSpy::allowing('view');
    $this->locator->shouldReceive('resolveDashboard')->andReturn($this->dashboard);

    $shape = QueryShape::attemptedBy(fn (): JsonResponse => ($this->controller)()->index(
        ($this->request)($this->viewer),
        (string) $this->dashboard->getKey(),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('dashboard_widgets'))->toBeTrue()
        ->and($shape->sql)->toContain('"dashboard_id" = ?')
        ->and($shape->sql)->toContain('order by "position" asc, "created_at" asc')
        ->and($shape->hasBinding((string) $this->dashboard->getKey()))->toBeTrue();
});

it('asks the locator for the widget within its dashboard and loads its report source', function (): void {
    GateSpy::allowing('view');

    $seen = null;

    $this->locator->shouldReceive('resolveWidget')
        ->andReturnUsing(function (User $user, string $dashboard, string $widget, bool $withReport) use (&$seen): DashboardWidget {
            $seen = [$dashboard, $widget, $withReport];

            return $this->widget;
        });

    $this->resolver->shouldReceive('resolve')->andThrow(new AuthorizationException('stop'));

    expect(fn (): JsonResponse => ($this->controller)()->show(($this->request)($this->viewer), 'board', 'tile'))
        ->toThrow(AuthorizationException::class)
        ->and($seen)->toBe(['board', 'tile', true]);
});
