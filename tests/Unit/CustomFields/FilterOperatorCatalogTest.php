<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Exceptions\CustomFields\UnknownFieldTypeException;
use App\Handlers\CustomFields\Abstracts\AbstractFieldHandler;
use App\Models\FieldDefinition;
use App\Support\CustomFields\FieldTypeRegistry;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    AccessContext::tenant();

    $this->registry = app(FieldTypeRegistry::class);

    /** @var callable(FieldType, array<string, mixed>):FieldDefinition */
    $this->field = static fn (FieldType $type, array $overrides = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        ['field_type' => $type->value, 'is_filterable' => true, 'is_encrypted' => false, ...$overrides],
    );

    /** @var callable(list<FilterOperator>):list<string> */
    $this->values = static function (array $operators): array {
        $values = array_map(static fn (FilterOperator $operator): string => $operator->value, $operators);
        sort($values);

        return $values;
    };

    /** @var callable(FieldType, array<string, mixed>):list<string> */
    $this->operatorsOf = fn (FieldType $type, array $overrides = []): array => ($this->values)(
        $this->registry->filterOperators(($this->field)($type, $overrides)),
    );

    $this->numericSet = ($this->values)([
        FilterOperator::Equals,
        FilterOperator::NotEqual,
        FilterOperator::GreaterThan,
        FilterOperator::GreaterThanOrEqual,
        FilterOperator::LessThan,
        FilterOperator::LessThanOrEqual,
        FilterOperator::InRange,
        FilterOperator::Blank,
        FilterOperator::NotBlank,
    ]);

    $this->stringSet = ($this->values)([
        FilterOperator::Equals,
        FilterOperator::NotEqual,
        FilterOperator::Contains,
        FilterOperator::NotContains,
        FilterOperator::StartsWith,
        FilterOperator::EndsWith,
        FilterOperator::In,
        FilterOperator::NotIn,
        FilterOperator::Blank,
        FilterOperator::NotBlank,
    ]);

    $this->temporalSet = ($this->values)([
        FilterOperator::Equals,
        FilterOperator::NotEqual,
        FilterOperator::GreaterThan,
        FilterOperator::LessThan,
        FilterOperator::InRange,
        FilterOperator::Blank,
        FilterOperator::NotBlank,
    ]);

    $this->membershipSet = ($this->values)([
        FilterOperator::In,
        FilterOperator::NotIn,
        FilterOperator::Blank,
        FilterOperator::NotBlank,
    ]);

    $this->presenceSet = ($this->values)([FilterOperator::Blank, FilterOperator::NotBlank]);

    $this->booleanSet = ($this->values)([FilterOperator::Equals, FilterOperator::Blank]);

    $this->relationSet = ($this->values)([FilterOperator::Has, FilterOperator::HasNot]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('offers every field type the operator set of its kind', function (FieldType $type, string $expectedSet): void {
    expect(($this->operatorsOf)($type))->toBe($this->{$expectedSet});
})->with([
    'short text' => [FieldType::TextShort, 'stringSet'],
    'long text' => [FieldType::TextLong, 'stringSet'],
    'url' => [FieldType::Url, 'stringSet'],
    'email' => [FieldType::Email, 'stringSet'],
    'phone' => [FieldType::Phone, 'stringSet'],
    'number' => [FieldType::Number, 'numericSet'],
    'money' => [FieldType::Money, 'numericSet'],
    'decimal' => [FieldType::Decimal, 'numericSet'],
    'roll-up' => [FieldType::Rollup, 'numericSet'],
    'date without the inclusive comparisons' => [FieldType::Date, 'temporalSet'],
    'datetime without the inclusive comparisons' => [FieldType::DateTime, 'temporalSet'],
    'boolean with equality and the blank check only' => [FieldType::Boolean, 'booleanSet'],
    'single select' => [FieldType::SingleSelect, 'membershipSet'],
    'multi select' => [FieldType::MultiSelect, 'membershipSet'],
    'file with presence only' => [FieldType::File, 'presenceSet'],
    'geo address with presence only' => [FieldType::GeoAddress, 'presenceSet'],
    'has many relation' => [FieldType::RelationHasMany, 'relationSet'],
    'many to many relation' => [FieldType::RelationManyToMany, 'relationSet'],
]);

it('derives the computed operator set from the configured result type', function (): void {
    expect(($this->operatorsOf)(FieldType::Computed, ['config' => ['formula' => '1 + 1', 'result_type' => 'number']]))
        ->toBe($this->numericSet)
        ->and(($this->operatorsOf)(FieldType::Computed, ['config' => ['formula' => '"a"', 'result_type' => 'text']]))
        ->toBe($this->stringSet);
});

it('offers no operator at all for an encrypted or non filterable field', function (): void {
    expect($this->registry->filterOperators(($this->field)(FieldType::TextShort, ['is_encrypted' => true])))->toBe([])
        ->and($this->registry->filterOperators(($this->field)(FieldType::Number, ['is_filterable' => false])))->toBe([]);
});

it('falls silent rather than throwing when a computed result type is missing or unusable', function (): void {
    $missing = ($this->field)(FieldType::Computed, ['config' => ['formula' => '1 + 1']]);
    $bogus = ($this->field)(FieldType::Computed, ['config' => ['formula' => '1 + 1', 'result_type' => 'bogus']]);

    expect(fn (): array => $this->registry->filterOperators($missing))->not->toThrow(UnknownFieldTypeException::class)
        ->and($this->registry->filterOperators($missing))->toBe([])
        ->and(fn (): array => $this->registry->filterOperators($bogus))->not->toThrow(UnknownFieldTypeException::class)
        ->and($this->registry->filterOperators($bogus))->toBe([]);
});

it('keeps the abstract handler default on the conservative operator set', function (): void {
    $handler = new class extends AbstractFieldHandler
    {
        public function fieldType(): FieldType
        {
            return FieldType::TextShort;
        }
    };

    expect(($this->values)($handler->filterOperators(($this->field)(FieldType::TextShort))))->toBe(($this->values)([
        FilterOperator::Equals,
        FilterOperator::NotEqual,
        FilterOperator::Blank,
        FilterOperator::NotBlank,
    ]));
});
