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
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('gives every text like field the string comparison operators', function (): void {
    foreach ([FieldType::TextShort, FieldType::TextLong, FieldType::Url, FieldType::Email, FieldType::Phone] as $type) {
        expect(($this->operatorsOf)($type))->toBe($this->stringSet);
    }
});

it('gives every numeric field the comparison and range operators', function (): void {
    foreach ([FieldType::Number, FieldType::Money, FieldType::Decimal, FieldType::Rollup] as $type) {
        expect(($this->operatorsOf)($type))->toBe($this->numericSet);
    }
});

it('withholds the inclusive comparisons from a date or datetime field', function (): void {
    foreach ([FieldType::Date, FieldType::DateTime] as $type) {
        expect(($this->operatorsOf)($type))->toBe($this->temporalSet)
            ->and(($this->operatorsOf)($type))->not->toContain(FilterOperator::GreaterThanOrEqual->value)
            ->and(($this->operatorsOf)($type))->not->toContain(FilterOperator::LessThanOrEqual->value);
    }
});

it('leaves a boolean field with equality and the blank check only', function (): void {
    expect(($this->operatorsOf)(FieldType::Boolean))->toBe(($this->values)([
        FilterOperator::Equals,
        FilterOperator::Blank,
    ]));
});

it('gives a select field membership and presence operators', function (): void {
    expect(($this->operatorsOf)(FieldType::SingleSelect))->toBe($this->membershipSet)
        ->and(($this->operatorsOf)(FieldType::MultiSelect))->toBe($this->membershipSet);
});

it('derives the computed operator set from the configured result type', function (): void {
    expect(($this->operatorsOf)(FieldType::Computed, ['config' => ['formula' => '1 + 1', 'result_type' => 'number']]))
        ->toBe($this->numericSet)
        ->and(($this->operatorsOf)(FieldType::Computed, ['config' => ['formula' => '"a"', 'result_type' => 'text']]))
        ->toBe($this->stringSet);
});

it('leaves a file or geo field with presence operators only', function (): void {
    expect(($this->operatorsOf)(FieldType::File))->toBe($this->presenceSet)
        ->and(($this->operatorsOf)(FieldType::GeoAddress))->toBe($this->presenceSet);
});

it('routes both relation types through one handler to the membership operators', function (): void {
    $expected = ($this->values)([FilterOperator::Has, FilterOperator::HasNot]);

    expect(($this->operatorsOf)(FieldType::RelationHasMany))->toBe($expected)
        ->and(($this->operatorsOf)(FieldType::RelationManyToMany))->toBe($expected);
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
