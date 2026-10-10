<?php

declare(strict_types=1);

use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportResultData;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\ReportExecutionMode;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Models\DashboardWidget;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\ReportExecutionContext;
use App\Support\Reports\ReportExecutionModeResolver;
use App\Support\Reports\ReportInputRules;
use App\Support\Reports\ReportRunner;
use App\Support\Reports\WidgetResultResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Log::spy();

    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::user($this->tenant, [], 'widget-viewer');

    $this->validator = Mockery::mock(ReportDefinitionValidator::class);
    $this->runner = Mockery::mock(ReportRunner::class);
    $this->context = Mockery::mock(ReportExecutionContext::class);
    $this->modes = Mockery::mock(ReportExecutionModeResolver::class);

    $this->resolver = fn (): WidgetResultResolver => new WidgetResultResolver(
        $this->validator,
        $this->runner,
        $this->context,
        $this->modes,
    );

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('widget-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
        'name' => 'Deals',
    ]);

    /** @var callable(array<string, mixed>):Report */
    $this->report = fn (array $attributes = []): Report => ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('widget-report'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('report-owner'),
        'object_type_id' => $this->objectType->getKey(),
        'aggregation_type' => AggregationType::Count->value,
        'filter_definition' => [],
        ...$attributes,
    ], ['objectType' => $this->objectType]);

    /** @var callable(array<string, mixed>, ?Report):DashboardWidget */
    $this->widget = fn (array $attributes = [], ?Report $report = null): DashboardWidget => ModelStub::make(
        DashboardWidget::class,
        [
            'id' => ModelStub::ulid('widget'),
            'tenant_id' => $this->tenant->getKey(),
            'dashboard_id' => ModelStub::ulid('widget-dashboard'),
            'report_id' => null,
            'goal_id' => null,
            'definition' => null,
            'position' => 0,
            'column_span' => 1,
            ...$attributes,
        ],
        ['report' => $report],
    );

    $this->definition = new ReportDefinitionData(
        (string) $this->objectType->getKey(),
        AggregationType::Count,
        null,
        null,
        null,
        null,
        [],
        [],
        [],
    );

    $this->result = new ReportResultData(AggregationType::Count, [], '4', 4, 0, false, '2026-09-23T10:00:00+00:00');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('answers with a notice instead of figures when the viewer may not see the report behind the tile', function (): void {
    GateSpy::allowing();
    $this->modes->shouldReceive('effectiveMode')->andReturn(ReportExecutionMode::Viewer);
    $this->runner->shouldNotReceive('run');
    $this->context->shouldNotReceive('runAsViewer');

    $report = ($this->report)();
    $widget = ($this->widget)(['report_id' => $report->getKey()], $report);

    $data = ($this->resolver)()->resolve($widget, $this->viewer);

    expect($data->result)->toBeNull()
        ->and($data->reason)->toBe(ReportNotExecutableReason::SourceNotVisible)
        ->and($data->objectType)->toBeNull();
});

it('answers with a notice when the report the tile names no longer resolves', function (): void {
    GateSpy::allowing('view');
    $this->modes->shouldReceive('effectiveMode')->andReturn(ReportExecutionMode::Viewer);
    $this->runner->shouldNotReceive('run');

    $widget = ($this->widget)(['report_id' => ModelStub::ulid('gone')], null);

    $data = ($this->resolver)()->resolve($widget, $this->viewer);

    expect($data->reason)->toBe(ReportNotExecutableReason::ReportMissing)
        ->and($data->executionMode)->toBe(ReportExecutionMode::Viewer);
});

it('answers with a notice when the embedded tile names an object type that no longer resolves', function (): void {
    GateSpy::allowing('create');
    $this->modes->shouldReceive('effectiveMode')->andReturn(ReportExecutionMode::Viewer);

    $widget = ($this->widget)(['definition' => ['object_type_id' => null]]);

    $data = ($this->resolver)()->resolve($widget, $this->viewer);

    expect($data->reason)->toBe(ReportNotExecutableReason::SourceNotVisible)
        ->and($data->result)->toBeNull();
});

it('hands the runner exactly the canonical definition keys of the report and runs in the viewer context', function (): void {
    GateSpy::allowing('view');
    $this->modes->shouldReceive('effectiveMode')->andReturn(ReportExecutionMode::Viewer);

    $report = ($this->report)(['filter_definition' => ['combinator' => 'and', 'conditions' => []]]);
    $widget = ($this->widget)(['report_id' => $report->getKey()], $report);

    $seen = null;
    $ranAs = null;

    $this->validator->shouldReceive('validate')
        ->andReturnUsing(function (array $definition) use (&$seen): ReportDefinitionData {
            $seen = $definition;

            return $this->definition;
        });

    $this->runner->shouldReceive('run')->andReturn($this->result);

    $this->context->shouldReceive('runAsViewer')
        ->andReturnUsing(function (string $tenantId, string $userId, Closure $callback) use (&$ranAs): ReportResultData {
            $ranAs = [$tenantId, $userId];

            return $callback($this->viewer);
        });

    $data = ($this->resolver)()->resolve($widget, $this->viewer);

    expect(array_keys((array) $seen))->toBe(ReportInputRules::definitionKeys())
        ->and($ranAs)->toBe([(string) $this->tenant->getKey(), (string) $this->viewer->getKey()])
        ->and($data->result)->toBe($this->result)
        ->and($data->objectType)->toBe($this->objectType)
        ->and($data->reason)->toBeNull();
});

it('runs a definer bound tile in the context of the report owner rather than the viewer', function (): void {
    GateSpy::allowing('view');
    $this->modes->shouldReceive('effectiveMode')->andReturn(ReportExecutionMode::Definer);

    $report = ($this->report)();
    $widget = ($this->widget)(['report_id' => $report->getKey()], $report);

    $ranAs = null;

    $this->validator->shouldReceive('validate')->andReturn($this->definition);
    $this->runner->shouldReceive('run')->andReturn($this->result);
    $this->context->shouldNotReceive('runAsViewer');
    $this->context->shouldReceive('runAsDefiner')
        ->andReturnUsing(function (string $tenantId, string $ownerId, Closure $callback) use (&$ranAs): ReportResultData {
            $ranAs = [$tenantId, $ownerId];

            return $callback($this->viewer);
        });

    $data = ($this->resolver)()->resolve($widget, $this->viewer);

    expect($ranAs)->toBe([(string) $this->tenant->getKey(), $report->owner_id])
        ->and($data->executionMode)->toBe(ReportExecutionMode::Definer);
});

it('turns a refusal raised during execution into a notice instead of letting it escape', function (): void {
    GateSpy::allowing('view');
    $this->modes->shouldReceive('effectiveMode')->andReturn(ReportExecutionMode::Viewer);

    $report = ($this->report)();
    $widget = ($this->widget)(['report_id' => $report->getKey()], $report);

    $this->validator->shouldReceive('validate')->andReturn($this->definition);
    $this->context->shouldReceive('runAsViewer')->andThrow(new AuthorizationException('nope'));

    $data = ($this->resolver)()->resolve($widget, $this->viewer);

    expect($data->reason)->toBe(ReportNotExecutableReason::SourceNotVisible)
        ->and($data->result)->toBeNull();
});

it('names no field key in the notice it hands back for a tile the viewer may not run', function (): void {
    GateSpy::allowing();
    $this->modes->shouldReceive('effectiveMode')->andReturn(ReportExecutionMode::Viewer);

    $report = ($this->report)(['aggregation_field_key' => 'gehalt']);
    $widget = ($this->widget)(['report_id' => $report->getKey()], $report);

    $data = ($this->resolver)()->resolve($widget, $this->viewer);

    expect($data->reason?->message())->not->toContain('gehalt')
        ->and($data->reason?->value)->not->toContain('gehalt');
});

it('reads the goal behind a tile only inside the tenant of the viewer', function (): void {
    GateSpy::allowing('view');
    $this->modes->shouldReceive('effectiveMode')->andReturn(ReportExecutionMode::Viewer);

    $goalId = ModelStub::ulid('pinned-goal');
    $widget = ($this->widget)(['goal_id' => $goalId]);

    $shape = QueryShape::attemptedBy(fn () => ($this->resolver)()->resolve($widget, $this->viewer));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('goals'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding($goalId))->toBeTrue();
});

it('refuses to drill into a goal tile at all', function (): void {
    $widget = ($this->widget)(['goal_id' => ModelStub::ulid('goal')]);

    expect(fn (): array => ($this->resolver)()->visibleDefinition($widget, $this->objectType, $this->viewer))
        ->toThrow(AuthorizationException::class);
});

it('refuses to drill into a tile whose report no longer resolves', function (): void {
    $widget = ($this->widget)(['report_id' => ModelStub::ulid('gone')], null);

    expect(fn (): array => ($this->resolver)()->visibleDefinition($widget, $this->objectType, $this->viewer))
        ->toThrow(AuthorizationException::class);
});

it('refuses a drill-down whose requested object type is not the one the tile analyses', function (): void {
    GateSpy::allowing('view');

    $report = ($this->report)();
    $widget = ($this->widget)(['report_id' => $report->getKey()], $report);

    $foreign = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('other-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'contacts',
    ]);

    expect(fn (): array => ($this->resolver)()->visibleDefinition($widget, $foreign, $this->viewer))
        ->toThrow(AuthorizationException::class);
});

it('refuses a drill-down to a viewer who may not see the source behind the tile', function (): void {
    $spy = GateSpy::allowing();

    $report = ($this->report)();
    $widget = ($this->widget)(['report_id' => $report->getKey()], $report);

    expect(fn (): array => ($this->resolver)()->visibleDefinition($widget, $this->objectType, $this->viewer))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('view'))->toBeTrue();
});

it('asks for the evaluation right on the object type before drilling into an embedded tile', function (): void {
    $spy = GateSpy::allowing();

    $widget = ($this->widget)(['definition' => ['object_type_id' => (string) $this->objectType->getKey()]]);

    expect(fn (): array => ($this->resolver)()->visibleDefinition($widget, $this->objectType, $this->viewer))
        ->toThrow(AuthorizationException::class)
        ->and($spy->wasAskedFor('create'))->toBeTrue();
});

it('hands the permitted drill-down only the canonical definition keys of the tile', function (): void {
    GateSpy::allowing('create');

    $widget = ($this->widget)([
        'definition' => [
            'object_type_id' => (string) $this->objectType->getKey(),
            'aggregation_type' => AggregationType::Count->value,
            'filter_definition' => [],
            'smuggled' => 'value',
        ],
    ]);

    $definition = ($this->resolver)()->visibleDefinition($widget, $this->objectType, $this->viewer);

    expect(array_keys($definition))->toBe(['aggregation_type', 'filter_definition'])
        ->and($definition)->not->toHaveKey('smuggled')
        ->and($definition)->not->toHaveKey('object_type_id');
});

it('carries the source object type of a report bound drill-down through to the caller', function (): void {
    GateSpy::allowing('view');

    $report = ($this->report)(['group_by_field_key' => 'stage']);
    $widget = ($this->widget)(['report_id' => $report->getKey()], $report);

    $definition = ($this->resolver)()->visibleDefinition($widget, $this->objectType, $this->viewer);

    expect(array_keys($definition))->toBe(ReportInputRules::definitionKeys())
        ->and($definition['group_by_field_key'])->toBe('stage');
});

it('refuses a tile of a viewer whose acting user cannot be resolved into the report source', function (): void {
    GateSpy::allowing();
    $this->modes->shouldReceive('effectiveMode')->andReturn(ReportExecutionMode::Viewer);

    $stranger = ModelStub::make(User::class, [
        'id' => ModelStub::ulid('stranger'),
        'tenant_id' => ModelStub::ulid('other-tenant'),
    ]);

    $report = ($this->report)();
    $widget = ($this->widget)(['report_id' => $report->getKey()], $report);

    $data = ($this->resolver)()->resolve($widget, $stranger);

    expect($data->reason)->toBe(ReportNotExecutableReason::SourceNotVisible);
});
