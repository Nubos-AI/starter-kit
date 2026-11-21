<?php

declare(strict_types=1);

use App\Actions\Reports\UpdateReportAction;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\ChartType;
use App\Enums\Reports\ReportExecutionMode;
use App\Models\Report;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Reports\EnsureReportIndexesStarter;
use App\Support\Reports\ReportDefinerSource;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\ReportExecutionModeResolver;
use App\Support\Reports\ReportInputRules;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(ReportExecutionMode):Report */
    $this->report = fn (ReportExecutionMode $mode): Report => ModelStub::make(Report::class, [
        'id' => ModelStub::ulid('report'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('owner'),
        'object_type_id' => ModelStub::ulid('deals'),
        'name' => 'Pipeline',
        'aggregation_type' => AggregationType::Count->value,
        'chart_type' => ChartType::Bar->value,
        'execution_mode' => $mode->value,
    ]);

    /** @var callable(?User):ReportExecutionModeResolver */
    $this->resolverFor = static function (?User $definer): ReportExecutionModeResolver {
        $source = Mockery::mock(ReportDefinerSource::class);
        $source->shouldReceive('find')->andReturn($definer);

        return new ReportExecutionModeResolver($source);
    };

    /** @var callable(bool):StaticAuthorityUser */
    $this->definer = function (bool $escalated): StaticAuthorityUser {
        /** @var StaticAuthorityUser $user */
        $user = ModelStub::make(StaticAuthorityUser::class, [
            'id' => ModelStub::ulid('owner'),
            'tenant_id' => $this->tenant->getKey(),
        ]);

        $user->escalated = $escalated;

        return $user;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('runs a report without an execution mode of its own in the viewer context', function (): void {
    expect(($this->resolverFor)(null)->effectiveMode(null))->toBe(ReportExecutionMode::Viewer);
});

it('never looks a definer up for a report that already runs as the viewer', function (): void {
    $source = Mockery::mock(ReportDefinerSource::class);
    $source->shouldReceive('find')->never();

    $mode = (new ReportExecutionModeResolver($source))->effectiveMode(($this->report)(ReportExecutionMode::Viewer));

    expect($mode)->toBe(ReportExecutionMode::Viewer);
});

it('grants the definer context only while its owner still holds the escalated authority', function (): void {
    $escalated = ($this->resolverFor)(($this->definer)(true))
        ->effectiveMode(($this->report)(ReportExecutionMode::Definer));

    $demoted = ($this->resolverFor)(($this->definer)(false))
        ->effectiveMode(($this->report)(ReportExecutionMode::Definer));

    expect($escalated)->toBe(ReportExecutionMode::Definer)
        ->and($demoted)->toBe(ReportExecutionMode::Viewer);
});

it('falls back to the viewer context when the owner of a definer report is gone', function (): void {
    expect(($this->resolverFor)(null)->effectiveMode(($this->report)(ReportExecutionMode::Definer)))
        ->toBe(ReportExecutionMode::Viewer);
});

it('looks the definer up by the owner key of the report', function (): void {
    $shape = QueryShape::attemptedBy(
        fn (): ReportExecutionMode => app(ReportExecutionModeResolver::class)
            ->effectiveMode(($this->report)(ReportExecutionMode::Definer)),
    );

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->isKeyedTo('users', ModelStub::ulid('owner')))->toBeTrue();
});

it('binds the object type of a new report to the tenant of the actor', function (): void {
    $rules = ReportInputRules::rules(true);

    expect($rules)->toHaveKey('object_type_id');

    $exists = array_values(array_filter(
        $rules['object_type_id'],
        static fn (mixed $rule): bool => $rule instanceof Exists,
    ));

    expect($exists)->toHaveCount(1)
        ->and((string) $exists[0])->toContain('object_types')
        ->and((string) $exists[0])->toContain('tenant_id');
});

it('never lets an update rewrite the object type of a saved report', function (): void {
    expect(ReportInputRules::rules(false))->not->toHaveKey('object_type_id')
        ->and(array_keys(ReportInputRules::attributes([
            'name' => 'Pipeline',
            'aggregation_type' => AggregationType::Count->value,
            'chart_type' => ChartType::Bar->value,
            'object_type_id' => ModelStub::ulid('smuggled'),
        ], ReportExecutionMode::Viewer)))->not->toContain('object_type_id');
});

it('refuses to switch a saved report to definer mode without the escalated authority', function (): void {
    $action = new UpdateReportAction(
        Mockery::mock(ReportDefinitionValidator::class),
        Mockery::mock(AdminArtifactAuditor::class),
        Mockery::mock(EnsureReportIndexesStarter::class),
    );

    $actor = ($this->definer)(false);
    $report = ($this->report)(ReportExecutionMode::Viewer);

    GateSpy::allowing('update');

    try {
        $action->execute($actor, $report, [
            'name' => 'Pipeline',
            'aggregation_type' => AggregationType::Count->value,
            'chart_type' => ChartType::Bar->value,
            'execution_mode' => ReportExecutionMode::Definer->value,
        ]);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['execution_mode']);

        return;
    }

    throw new RuntimeException('The report was switched to definer mode without an escalated authority.');
});

it('leaves a report on its stored mode when the update names no execution mode', function (): void {
    $action = new UpdateReportAction(
        Mockery::mock(ReportDefinitionValidator::class),
        Mockery::mock(AdminArtifactAuditor::class),
        Mockery::mock(EnsureReportIndexesStarter::class),
    );

    $actor = ($this->definer)(false);
    $report = ($this->report)(ReportExecutionMode::Definer);

    GateSpy::allowing('update');

    $reached = QueryShape::attemptedBy(
        fn (): Report => $action->execute($actor, $report, [
            'name' => 'Pipeline',
            'aggregation_type' => AggregationType::Count->value,
            'chart_type' => ChartType::Bar->value,
        ]),
    );

    expect($reached)->not->toBeNull()
        ->and($reached->targets('object_types'))->toBeTrue();
});
