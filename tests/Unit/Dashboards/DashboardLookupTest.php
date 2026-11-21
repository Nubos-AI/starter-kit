<?php

declare(strict_types=1);

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Support\Dashboards\DashboardWidgetLocator;
use App\Support\Dashboards\DefaultDashboardResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
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
    $this->user = AccessContext::user($this->tenant, [], 'lookup-user');

    $this->locator = new DashboardWidgetLocator;
    $this->resolver = new DefaultDashboardResolver;

    /** @var callable(string, ?string):Dashboard */
    $this->board = fn (string $seed, ?string $ownerId = null): Dashboard => ModelStub::make(Dashboard::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $ownerId ?? ModelStub::ulid('stranger'),
        'name' => $seed,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks a dashboard up inside the tenant of the caller and never by identifier alone', function (): void {
    $dashboardId = ModelStub::ulid('wanted');

    $shape = QueryShape::attemptedBy(fn (): Dashboard => $this->locator->resolveDashboard($this->user, $dashboardId));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('dashboards'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('dashboards', $dashboardId))->toBeTrue()
        ->and($shape->hidesSoftDeleted('dashboards'))->toBeTrue();
});

it('resolves the dashboard of a widget before the widget itself so a foreign board never answers', function (): void {
    $dashboardId = ModelStub::ulid('wanted');

    $shape = QueryShape::attemptedBy(fn (): DashboardWidget => $this->locator->resolveWidget(
        $this->user,
        $dashboardId,
        ModelStub::ulid('wanted-widget'),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('dashboards'))->toBeTrue()
        ->and($shape->isKeyedTo('dashboards', $dashboardId))->toBeTrue();
});

it('loads the report and its object type with the widget only when the caller asks for the report source', function (bool $withReport): void {
    $tenantId = (string) $this->tenant->getKey();
    $connection = StaticQueryConnection::install(static fn (string $sql): array => match (true) {
        str_contains($sql, 'from "dashboards"') => [['id' => ModelStub::ulid('wanted'), 'tenant_id' => $tenantId, 'owner_id' => ModelStub::ulid('stranger'), 'name' => 'Board']],
        str_contains($sql, 'from "dashboard_widgets"') => [['id' => ModelStub::ulid('wanted-widget'), 'tenant_id' => $tenantId, 'dashboard_id' => ModelStub::ulid('wanted'), 'report_id' => ModelStub::ulid('widget-report')]],
        str_contains($sql, 'from "reports"') => [['id' => ModelStub::ulid('widget-report'), 'tenant_id' => $tenantId, 'object_type_id' => ModelStub::ulid('widget-type')]],
        default => [],
    });

    try {
        $this->locator->resolveWidget($this->user, ModelStub::ulid('wanted'), ModelStub::ulid('wanted-widget'), $withReport);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $tables = array_map(
        static fn (string $sql): string => Str::betweenFirst($sql, 'from "', '"'),
        array_column($connection->queries, 'sql'),
    );

    expect(in_array('reports', $tables, true))->toBe($withReport)
        ->and(in_array('object_types', $tables, true))->toBe($withReport);
})->with([
    'with the report source' => [true],
    'without the report source' => [false],
]);

it('offers no dashboard at all to a caller without a tenant and asks the database nothing', function (): void {
    $homeless = ModelStub::make(User::class, ['id' => ModelStub::ulid('homeless'), 'tenant_id' => null]);

    $shape = QueryShape::attemptedBy(fn (): EloquentCollection => $this->resolver->available($homeless));

    expect($shape)->toBeNull()
        ->and($this->resolver->available($homeless))->toBeEmpty()
        ->and($this->resolver->resolve($homeless))->toBeNull();
});

it('reads the available dashboards inside the tenant in a stable creation order', function (): void {
    $shape = QueryShape::attemptedBy(fn (): EloquentCollection => $this->resolver->available($this->user));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('dashboards'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->sql)->toContain('order by "created_at" asc, "id" asc')
        ->and($shape->hidesSoftDeleted('dashboards'))->toBeTrue();
});

it('honours the dashboard the caller asked for over their stored start page and over the first available', function (): void {
    $asked = ($this->board)('asked');
    $stored = ($this->board)('stored');
    $first = ($this->board)('first');

    $available = new EloquentCollection([$first, $stored, $asked]);
    $user = AccessContext::user($this->tenant, ['default_dashboard_id' => $stored->getKey()], 'picker');

    expect($this->resolver->select($user, $available, (string) $asked->getKey()))->toBe($asked)
        ->and($this->resolver->select($user, $available, null))->toBe($stored)
        ->and($this->resolver->select($user, $available, ''))->toBe($stored);
});

it('falls back to the first available dashboard when the stored start page is no longer among them', function (): void {
    $first = ($this->board)('first');
    $available = new EloquentCollection([$first]);

    $user = AccessContext::user($this->tenant, ['default_dashboard_id' => ModelStub::ulid('vanished')], 'picker');

    expect($this->resolver->select($user, $available, ModelStub::ulid('also-gone')))->toBe($first);
});

it('hands back nothing at all when the caller has no visible dashboard left', function (): void {
    $user = AccessContext::user($this->tenant, ['default_dashboard_id' => ModelStub::ulid('vanished')], 'picker');

    expect($this->resolver->select($user, new EloquentCollection, null))->toBeNull();
});

it('splits the available dashboards into the own ones and the ones somebody shared', function (): void {
    $user = AccessContext::user($this->tenant, [], 'grouper');

    $own = ($this->board)('own', (string) $user->getKey());
    $shared = ($this->board)('shared');

    $grouped = $this->resolver->group($user, new EloquentCollection([$shared, $own]));

    expect($grouped['own'])->toBe([$own])
        ->and($grouped['shared'])->toBe([$shared]);
});

it('keeps every dashboard route behind the authenticated team prefixed chain and a ulid constraint', function (): void {
    $show = RouteShape::named('dashboards.show');
    $results = RouteShape::named('dashboards.widgets.results.show');

    expect($show->hasDeclaredMiddleware('auth'))->toBeTrue()
        ->and($show->hasDeclaredMiddleware('verified'))->toBeTrue()
        ->and($show->constraints())->toHaveKey('dashboard')
        ->and($results->constraints())->toHaveKeys(['dashboard', 'widget'])
        ->and($results->handledBy())->toContain('DashboardWidgetResultsController');
});
