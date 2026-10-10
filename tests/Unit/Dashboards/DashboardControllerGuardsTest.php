<?php

declare(strict_types=1);

use App\Actions\Dashboards\AddDashboardWidgetAction;
use App\Actions\Dashboards\RemoveDashboardWidgetAction;
use App\Actions\Dashboards\UpdateDashboardLayoutAction;
use App\Actions\Dashboards\UpdateDashboardWidgetAction;
use App\Http\Controllers\Dashboards\DashboardShareOptionsController;
use App\Http\Controllers\Dashboards\DashboardWidgetsController;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Support\Dashboards\DashboardWidgetLocator;
use App\Support\Sharing\ShareGranteeOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::user($this->tenant, [], 'controller-viewer');

    $this->dashboard = ModelStub::make(Dashboard::class, [
        'id' => ModelStub::ulid('controller-dashboard'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('controller-owner'),
        'name' => 'Board',
    ]);

    $this->widget = ModelStub::make(DashboardWidget::class, [
        'id' => ModelStub::ulid('controller-widget'),
        'tenant_id' => $this->tenant->getKey(),
        'dashboard_id' => $this->dashboard->getKey(),
    ], ['dashboard' => $this->dashboard]);

    $this->locator = Mockery::mock(DashboardWidgetLocator::class);
    $this->locator->shouldReceive('resolveDashboard')->andReturn($this->dashboard)->byDefault();
    $this->locator->shouldReceive('resolveWidget')->andReturn($this->widget)->byDefault();

    $this->addWidget = Mockery::mock(AddDashboardWidgetAction::class);
    $this->updateWidget = Mockery::mock(UpdateDashboardWidgetAction::class);
    $this->removeWidget = Mockery::mock(RemoveDashboardWidgetAction::class);
    $this->updateLayout = Mockery::mock(UpdateDashboardLayoutAction::class);

    $this->widgets = fn (): DashboardWidgetsController => new DashboardWidgetsController(
        $this->addWidget,
        $this->updateWidget,
        $this->removeWidget,
        $this->updateLayout,
        $this->locator,
    );

    /** @var callable(?User, string, array<string, mixed>):Request */
    $this->request = function (?User $user, string $method = 'POST', array $payload = []): Request {
        $request = Request::create('/dashboards/x/widgets', $method, $payload);
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to add a tile to a dashboard the caller may not change and never reaches the action', function (): void {
    $spy = GateSpy::allowing('view');
    $this->addWidget->shouldNotReceive('execute');

    expect(fn (): JsonResponse => ($this->widgets)()->store(($this->request)($this->viewer), 'board'))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('update'))->toBeTrue();
});

it('refuses to change a tile of a dashboard the caller may not change', function (): void {
    GateSpy::allowing('view');
    $this->updateWidget->shouldNotReceive('execute');

    expect(fn (): JsonResponse => ($this->widgets)()->update(($this->request)($this->viewer, 'PUT'), 'board', 'tile'))
        ->toThrow(AuthorizationException::class);
});

it('refuses to remove a tile of a dashboard the caller may not change', function (): void {
    $spy = GateSpy::allowing('view');
    $this->removeWidget->shouldNotReceive('execute');

    expect(fn (): JsonResponse => ($this->widgets)()->destroy(($this->request)($this->viewer, 'DELETE'), 'board', 'tile'))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('delete'))->toBeTrue();
});

it('refuses to rewrite the arrangement of a dashboard the caller may not change', function (): void {
    GateSpy::allowing('view');
    $this->updateLayout->shouldNotReceive('execute');

    expect(fn (): JsonResponse => ($this->widgets)()->updateLayout(($this->request)($this->viewer, 'PUT'), 'board'))
        ->toThrow(AuthorizationException::class);
});

it('hands the permitted caller through to the action with the whole request payload', function (): void {
    GateSpy::allowing('view', 'update');

    $seen = null;

    $this->addWidget->shouldReceive('execute')
        ->andReturnUsing(function (User $user, Dashboard $dashboard, array $input) use (&$seen): DashboardWidget {
            $seen = [$dashboard, $input];

            return $this->widget;
        });

    $response = ($this->widgets)()->store(
        ($this->request)($this->viewer, 'POST', ['title' => 'Umsatz', 'column_span' => 2]),
        'board',
    );

    expect($response->getStatusCode())->toBe(201)
        ->and($seen[0])->toBe($this->dashboard)
        ->and($seen[1])->toBe(['title' => 'Umsatz', 'column_span' => 2]);
});

it('refuses the recipient lists to a caller who may not share the dashboard', function (): void {
    $spy = GateSpy::allowing('view', 'update');
    $options = Mockery::mock(ShareGranteeOptions::class);
    $options->shouldNotReceive('forUser');

    $controller = new DashboardShareOptionsController($this->locator, $options);

    expect(fn (): JsonResponse => $controller(($this->request)($this->viewer, 'GET'), 'board'))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('share'))->toBeTrue();
});

it('narrows the recipient lists with the trimmed search term of the caller', function (): void {
    GateSpy::allowing('view', 'share');

    $seen = null;
    $options = Mockery::mock(ShareGranteeOptions::class);
    $options->shouldReceive('forUser')
        ->andReturnUsing(function (User $user, string $term) use (&$seen): array {
            $seen = $term;

            return ['users' => [], 'teams' => [], 'roles' => []];
        });

    $controller = new DashboardShareOptionsController($this->locator, $options);

    $controller(($this->request)($this->viewer, 'GET', ['q' => '  anna  ']), 'board');

    expect($seen)->toBe('anna');
});
