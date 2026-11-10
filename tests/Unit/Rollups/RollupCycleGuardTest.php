<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Support\Engine\FieldDependencyGraphGuard;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Formulas\FormulaParser;
use App\Support\Formulas\FormulaReferenceCollector;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->objectTypeId = ModelStub::ulid('object-type');

    /** @var callable(string, FieldType, array<string, mixed>|null):FieldDefinition */
    $this->field = fn (string $key, FieldType $type, ?array $config = null): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('field-'.$key),
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => $type,
            'config' => $config,
        ],
    );

    /** @var callable(list<array<string, mixed>>):array<string, mixed> */
    $this->filter = static fn (array $conditions): array => ['combinator' => 'and', 'conditions' => $conditions];

    /** @var callable(list<FieldDefinition>):FieldDependencyGraphGuard */
    $this->guardOver = function (array $computedFields): FieldDependencyGraphGuard {
        $lookup = Mockery::mock(ObjectTypeFieldLookup::class);
        $lookup->shouldReceive('computedFields')
            ->with($this->objectTypeId)
            ->andReturn(new Collection($computedFields));

        return new FieldDependencyGraphGuard(
            app(FormulaParser::class),
            app(FormulaReferenceCollector::class),
            app(FilterFieldKeyCollector::class),
            $lookup,
        );
    };
});

it('refuses a roll-up that aggregates a field which aggregates it back', function (): void {
    $first = ($this->field)('a', FieldType::Rollup, ['aggregate' => 'sum', 'source_field_key' => 'b']);
    $second = ($this->field)('b', FieldType::Rollup, ['aggregate' => 'sum', 'source_field_key' => 'a']);

    $guard = ($this->guardOver)([$first, $second]);

    expect(fn (): mixed => $guard->guardAcyclic($first))
        ->toThrow(ValidationException::class);

    try {
        $guard->guardAcyclic($first);
    } catch (ValidationException $exception) {
        expect(implode(' ', $exception->errors()['config']))->toContain('a → b → a');
    }
});

it('refuses a roll-up that aggregates itself', function (): void {
    $field = ($this->field)('x', FieldType::Rollup, ['aggregate' => 'sum', 'source_field_key' => 'x']);

    expect(fn (): mixed => ($this->guardOver)([$field])->guardAcyclic($field))
        ->toThrow(ValidationException::class);
});

it('refuses a cycle that only closes over a third roll-up', function (): void {
    $a = ($this->field)('a', FieldType::Rollup, ['aggregate' => 'sum', 'source_field_key' => 'b']);
    $b = ($this->field)('b', FieldType::Rollup, ['aggregate' => 'sum', 'source_field_key' => 'c']);
    $c = ($this->field)('c', FieldType::Rollup, ['aggregate' => 'sum', 'source_field_key' => 'a']);

    expect(fn (): mixed => ($this->guardOver)([$a, $b, $c])->guardAcyclic($a))
        ->toThrow(ValidationException::class);
});

it('counts a field a roll-up only filters on as a dependency and refuses the cycle it closes', function (): void {
    $rollup = ($this->field)('total', FieldType::Rollup, [
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'filter' => ($this->filter)([
            ['field' => 'ratio', 'operator' => 'greaterThan', 'value' => 1],
        ]),
    ]);

    $formula = ($this->field)('ratio', FieldType::Computed, [
        'formula' => '{total} + 1',
        'result_type' => 'number',
    ]);

    expect(fn (): mixed => ($this->guardOver)([$rollup, $formula])->guardAcyclic($rollup))
        ->toThrow(ValidationException::class);
});

it('counts a filter field buried in a nested condition group just the same', function (): void {
    $rollup = ($this->field)('total', FieldType::Rollup, [
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'filter' => ($this->filter)([
            ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
            ['combinator' => 'or', 'conditions' => [
                ['field' => 'ratio', 'operator' => 'greaterThan', 'value' => 1],
            ]],
        ]),
    ]);

    $formula = ($this->field)('ratio', FieldType::Computed, [
        'formula' => '{total} + 1',
        'result_type' => 'number',
    ]);

    expect(fn (): mixed => ($this->guardOver)([$rollup, $formula])->guardAcyclic($rollup))
        ->toThrow(ValidationException::class);
});

it('accepts a roll-up that filters on a formula field which does not read it back', function (): void {
    $rollup = ($this->field)('total', FieldType::Rollup, [
        'aggregate' => 'sum',
        'source_field_key' => 'amount',
        'filter' => ($this->filter)([
            ['field' => 'child_score', 'operator' => 'greaterThan', 'value' => 1],
        ]),
    ]);

    $formula = ($this->field)('child_score', FieldType::Computed, [
        'formula' => '{amount} + 1',
        'result_type' => 'number',
    ]);

    ($this->guardOver)([$rollup, $formula])->guardAcyclic($rollup);
})->throwsNoExceptions();

it('logs the formula it cannot parse and keeps checking the remaining fields', function (): void {
    Log::spy();

    $broken = ($this->field)('broken', FieldType::Computed, ['formula' => '{amount} +']);
    $cyclic = ($this->field)('a', FieldType::Rollup, ['aggregate' => 'sum', 'source_field_key' => 'a']);

    expect(fn (): mixed => ($this->guardOver)([$broken, $cyclic])->guardAcyclic($cyclic))
        ->toThrow(ValidationException::class);

    Log::shouldHaveReceived('warning')->withArgs(
        static fn (string $message, array $context): bool => str_starts_with($message, 'Skipped a computed field with an unusable formula')
            && ($context['field_key'] ?? null) === 'broken',
    )->once();
});

it('logs an empty formula instead of treating it as a dependency', function (): void {
    Log::spy();

    $empty = ($this->field)('empty', FieldType::Computed, ['formula' => '   ']);

    ($this->guardOver)([$empty])->guardAcyclic($empty);

    Log::shouldHaveReceived('warning')->withArgs(
        static fn (string $message, array $context): bool => ($context['field_key'] ?? null) === 'empty',
    )->once();
});
