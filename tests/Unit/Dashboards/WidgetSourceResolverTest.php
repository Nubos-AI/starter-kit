<?php

declare(strict_types=1);

use App\Enums\Reports\ReportExecutionMode;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\WidgetSourceResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant, [], 'source-actor');

    $this->resolver = new WidgetSourceResolver(Mockery::mock(ReportDefinitionValidator::class));

    /** @var callable(array<string, mixed>):ValidationException|null */
    $this->refusal = function (array $input): ?ValidationException {
        try {
            $this->resolver->resolve($this->actor, $input);
        } catch (ValidationException $exception) {
            return $exception;
        }

        return null;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('insists on exactly one source and names all three keys', function (array $input): void {
    $refusal = ($this->refusal)($input);

    expect($refusal)->not->toBeNull()
        ->and(array_keys($refusal->errors()))->toBe(['report_id', 'object_type_id', 'goal_id']);
})->with([
    'none was given' => [[]],
    'a report and a goal at the same time' => [['report_id' => ModelStub::ulid('report'), 'goal_id' => ModelStub::ulid('goal')]],
    'a report and an object type at the same time' => [['report_id' => ModelStub::ulid('report'), 'object_type_id' => ModelStub::ulid('type')]],
    'empty strings treated as no source rather than as a chosen one' => [['report_id' => '', 'goal_id' => '', 'object_type_id' => '']],
]);

it('refuses an own definition that asks to run in the definer context before it reads the object type', function (): void {
    $refusal = null;

    $shape = QueryShape::attemptedBy(function () use (&$refusal): void {
        try {
            $this->resolver->resolve($this->actor, [
                'object_type_id' => ModelStub::ulid('type'),
                'execution_mode' => ReportExecutionMode::Definer->value,
            ]);
        } catch (ValidationException $exception) {
            $refusal = $exception;
        }
    });

    expect($refusal)->not->toBeNull()
        ->and(array_keys($refusal->errors()))->toBe(['execution_mode'])
        ->and($shape)->toBeNull();
});

it('looks a referenced report up inside the bound tenant and never by identifier alone', function (): void {
    $reportId = ModelStub::ulid('wanted-report');

    $shape = QueryShape::attemptedBy(fn (): array => $this->resolver->resolve($this->actor, ['report_id' => $reportId]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('reports'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('reports', $reportId))->toBeTrue();
});

it('looks a referenced goal up inside the bound tenant as well', function (): void {
    $goalId = ModelStub::ulid('wanted-goal');

    $shape = QueryShape::attemptedBy(fn (): array => $this->resolver->resolve($this->actor, ['goal_id' => $goalId]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('goals'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->isKeyedTo('goals', $goalId))->toBeTrue();
});

it('falls back to the tenant of the acting user when no tenant is bound to the run', function (): void {
    AccessContext::forgetTenant();

    expect(TenantContext::currentId())->toBeNull();

    $shape = QueryShape::attemptedBy(fn (): array => $this->resolver->resolve(
        $this->actor,
        ['report_id' => ModelStub::ulid('wanted-report')],
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding((string) $this->actor->tenant_id))->toBeTrue();
});

it('reads the object type of an own definition before it asks the gate for the evaluation right', function (): void {
    $spy = GateSpy::allowing();
    $objectTypeId = ModelStub::ulid('own-type');

    $shape = QueryShape::attemptedBy(fn (): array => $this->resolver->resolve(
        $this->actor,
        ['object_type_id' => $objectTypeId],
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('object_types'))->toBeTrue()
        ->and($shape->isKeyedTo('object_types', $objectTypeId))->toBeTrue()
        ->and($spy->calls)->toBe([]);
});

it('offers the column span only within the three steps and demands a chart type without a goal', function (array $input, array $failing): void {
    $validator = Validator::make($input, $this->resolver->rules());

    expect(array_keys($validator->errors()->toArray()))->toBe($failing);
})->with([
    'no column span at all' => [['chart_type' => 'bar'], []],
    'a span of nought' => [['chart_type' => 'bar', 'column_span' => 0], ['column_span']],
    'a span of one' => [['chart_type' => 'bar', 'column_span' => 1], []],
    'a span of three' => [['chart_type' => 'bar', 'column_span' => 3], []],
    'a span of four' => [['chart_type' => 'bar', 'column_span' => 4], ['column_span']],
    'neither a chart type nor a goal' => [[], ['chart_type']],
    'a goal without a chart type' => [['goal_id' => 'goal'], []],
]);
