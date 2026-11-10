<?php

declare(strict_types=1);

use App\Actions\Dashboards\AddDashboardWidgetAction;
use App\Contracts\Dashboards\DashboardTileSourceInterface;
use App\DTOs\Reports\ReportResultData;
use App\DTOs\Reports\WidgetResultData;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\ChartType;
use App\Enums\Reports\GroupingBucket;
use App\Enums\Reports\ReportExecutionMode;
use App\Http\Resources\Dashboards\DashboardWidgetResource;
use App\Http\Resources\Dashboards\WidgetResultResource;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Support\Dashboards\DashboardTileSources;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\WidgetSourceResolver;
use Illuminate\Http\Request;
use Tests\Fixtures\Dashboards\StaticDashboardTileSource;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, [], 'tile-actor');

    $this->widget = fn (array $attributes = []): DashboardWidget => ModelStub::make(DashboardWidget::class, [
        'id' => ModelStub::ulid('module-widget'),
        'tenant_id' => $this->tenant->getKey(),
        'dashboard_id' => ModelStub::ulid('module-dashboard'),
        'report_id' => null,
        'goal_id' => null,
        'source_key' => 'fixture.revenue',
        'title' => 'Umsatz',
        'chart_type' => ChartType::Metric->value,
        'definition' => null,
        'position' => 0,
        'column_span' => 1,
        ...$attributes,
    ]);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('finds a source by its key and answers nothing for a key no module registered', function (): void {
    $revenue = new StaticDashboardTileSource('fixture.revenue');
    $churn = new StaticDashboardTileSource('fixture.churn');

    $sources = new DashboardTileSources([$revenue, $churn]);

    expect($sources->find('fixture.churn'))->toBe($churn)
        ->and($sources->find('fixture.unknown'))->toBeNull()
        ->and($sources->all())->toBe([$revenue, $churn]);
});

it('collects every tile source a module tags with the core interface', function (): void {
    $source = new StaticDashboardTileSource('fixture.tagged');

    app()->instance(StaticDashboardTileSource::class, $source);
    app()->tag([StaticDashboardTileSource::class], DashboardTileSourceInterface::class);
    app()->forgetInstance(DashboardTileSources::class);

    expect(app(DashboardTileSources::class)->find('fixture.tagged'))->toBe($source);
});

it('stores the key of a module tile and no other source on the new widget', function (): void {
    GateSpy::allowing('update');

    $connection = StaticQueryConnection::install(static fn (): array => [['aggregate' => null]], static fn (): int => 1);
    $dashboard = ModelStub::make(Dashboard::class, [
        'id' => ModelStub::ulid('module-dashboard'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->actor->getKey(),
    ]);
    $resolver = new WidgetSourceResolver(
        Mockery::mock(ReportDefinitionValidator::class),
        new DashboardTileSources([new StaticDashboardTileSource('fixture.revenue')]),
    );

    $widget = (new AddDashboardWidgetAction($resolver))->execute($this->actor, $dashboard, [
        'source_key' => 'fixture.revenue',
        'chart_type' => ChartType::Metric->value,
        'title' => 'Umsatz',
    ]);

    $insert = collect($connection->writtenStatements)->first(static fn (array $statement): bool => str_contains($statement['sql'], 'insert into "dashboard_widgets"')) ?? ['sql' => '', 'bindings' => []];

    expect($widget->source_key)->toBe('fixture.revenue')
        ->and($widget->report_id)->toBeNull()
        ->and($widget->goal_id)->toBeNull()
        ->and($widget->definition)->toBeNull()
        ->and($insert['sql'])->toContain('"source_key"')
        ->and($insert['bindings'])->toContain('fixture.revenue');
});

it('hands the source key of a widget and the currency and bucket of its result to the client', function (): void {
    $widget = ($this->widget)();
    $request = Request::create('/dashboards', 'GET');

    $meta = (new DashboardWidgetResource($widget))->resolve($request);
    $tile = (new WidgetResultResource(new WidgetResultData(
        $widget,
        ReportExecutionMode::Viewer,
        '2026-11-08T09:00:00+00:00',
        new ReportResultData(AggregationType::Sum, [], '1250.50', 3, 0, false, '2026-11-08T09:00:00+00:00'),
        null,
        null,
        null,
        'EUR',
        GroupingBucket::Month,
    )))->resolve($request);

    expect($meta['source_key'])->toBe('fixture.revenue')
        ->and($tile['source_key'])->toBe('fixture.revenue')
        ->and($tile['currency'])->toBe('EUR')
        ->and($tile['bucket'])->toBe('month')
        ->and($tile['result']['total'])->toBe('1250.50');
});

it('leaves currency and bucket empty for a tile that does not come from a module', function (): void {
    $widget = ($this->widget)(['source_key' => null, 'report_id' => ModelStub::ulid('report')]);

    $tile = (new WidgetResultResource(new WidgetResultData(
        $widget,
        ReportExecutionMode::Viewer,
        '2026-11-08T09:00:00+00:00',
        null,
        null,
        null,
    )))->resolve(Request::create('/dashboards', 'GET'));

    expect($tile['source_key'])->toBeNull()
        ->and($tile['currency'])->toBeNull()
        ->and($tile['bucket'])->toBeNull();
});

it('keeps the source key among the regular columns and lets the database demand exactly one of the four sources', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000048_create_dashboard_widgets_table.php');

    expect($schema->columnsOf('dashboard_widgets'))->toBe([
        'id',
        'tenant_id',
        'dashboard_id',
        'report_id',
        'goal_id',
        'source_key',
        'title',
        'chart_type',
        'definition',
        'position',
        'column_span',
        'created_at',
        'updated_at',
    ])
        ->and($schema->columnDefinition('dashboard_widgets', 'source_key'))->toContain('varchar')->not->toContain('not null')
        ->and($schema->statementMatching('chk_dashboard_widgets_source_exclusive'))->toContain('num_nonnulls(report_id, definition, goal_id, source_key) = 1');
});
