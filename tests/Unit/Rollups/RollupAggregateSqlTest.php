<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Handlers\CustomFields\RollupFieldHandler;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\RollupChildResolver;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('tenant');

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('anchor'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => ModelStub::ulid('parent-type'),
        'data' => [],
    ]);

    $this->aggregated = new stdClass;
    $this->aggregated->expression = null;
    $this->aggregated->bindings = null;

    /** @var callable():RollupFieldHandler */
    $this->handler = function (): RollupFieldHandler {
        $base = Mockery::mock(QueryBuilder::class);

        $base->shouldReceive('selectRaw')
            ->andReturnUsing(function (string $expression, array $bindings = []) use ($base): QueryBuilder {
                $this->aggregated->expression = $expression;
                $this->aggregated->bindings = $bindings;

                return $base;
            });

        $builder = Mockery::mock(EloquentBuilder::class);
        $builder->shouldReceive('toBase')->andReturn($base);

        $resolver = Mockery::mock(RollupChildResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($builder);

        return new RollupFieldHandler($resolver);
    };

    /** @var callable(array<string, mixed>):FieldDefinition */
    $this->rollup = fn (array $config): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('rollup'),
        'object_type_id' => (string) $this->record->getAttribute('object_type_id'),
        'key' => 'total',
        'field_type' => FieldType::Rollup,
        'config' => $config,
    ]);
});

it('lets the database aggregate the source field instead of looping in php', function (string $aggregate, string $expression): void {
    ($this->handler)()->aggregateQuery(
        ($this->rollup)(['aggregate' => $aggregate, 'source_field_key' => 'amount']),
        $this->record,
    );

    expect($this->aggregated->expression)->toBe($expression)
        ->and($this->aggregated->bindings)->toBe(['amount']);
})->with([
    'sum' => ['sum', 'SUM((custom_records.data->>?)::numeric) as aggregate'],
    'avg' => ['avg', 'AVG((custom_records.data->>?)::numeric) as aggregate'],
    'min' => ['min', 'MIN((custom_records.data->>?)::numeric) as aggregate'],
    'max' => ['max', 'MAX((custom_records.data->>?)::numeric) as aggregate'],
]);

it('counts the rows themselves and binds no source field for a counting roll-up', function (): void {
    ($this->handler)()->aggregateQuery(
        ($this->rollup)(['aggregate' => 'count', 'source_field_key' => 'amount']),
        $this->record,
    );

    expect($this->aggregated->expression)->toBe('COUNT(*) as aggregate')
        ->and($this->aggregated->bindings)->toBe([]);
});

it('falls back to summing when the stored aggregate is unknown', function (): void {
    ($this->handler)()->aggregateQuery(
        ($this->rollup)(['aggregate' => 'median', 'source_field_key' => 'amount']),
        $this->record,
    );

    expect($this->aggregated->expression)->toBe('SUM((custom_records.data->>?)::numeric) as aggregate');
});

it('falls back to summing an empty source key when the configuration is not even an array', function (): void {
    $field = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('rollup'),
        'key' => 'total',
        'field_type' => FieldType::Rollup,
        'config' => null,
    ]);

    ($this->handler)()->aggregateQuery($field, $this->record);

    expect($this->aggregated->expression)->toBe('SUM((custom_records.data->>?)::numeric) as aggregate')
        ->and($this->aggregated->bindings)->toBe(['']);
});

it('starts a counting roll-up over nothing at zero and every other aggregate at null', function (string $aggregate, ?int $expected): void {
    $handler = ($this->handler)();

    expect($handler->emptyValue(($this->rollup)(['aggregate' => $aggregate, 'source_field_key' => 'amount'])))->toBe($expected);
})->with([
    'count' => ['count', 0],
    'sum' => ['sum', null],
    'avg' => ['avg', null],
    'min' => ['min', null],
    'max' => ['max', null],
    'unknown' => ['median', null],
]);

it('never takes a roll-up value from the payload a user sends', function (): void {
    $handler = ($this->handler)();
    $field = ($this->rollup)(['aggregate' => 'sum', 'source_field_key' => 'amount']);

    expect($handler->fieldType())->toBe(FieldType::Rollup)
        ->and($handler->cast(42, $field))->toBeNull()
        ->and($handler->validationRules($field))->toBe(['nullable'])
        ->and($handler->filterOperators($field))->toBe(FilterOperator::forNumber());
});
