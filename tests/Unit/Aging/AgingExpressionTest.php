<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\AgingClock;
use App\Enums\Engine\AgingThresholdColor;
use App\Models\AgingRule;
use App\Models\FieldDefinition;
use App\Support\Aging\AgingExpressionBuilder;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->builder = app(AgingExpressionBuilder::class);

    $this->ruleWith = fn (array $attributes = []): AgingRule => ModelStub::make(AgingRule::class, [
        'id' => ModelStub::ulid('aging-expression-rule'),
        'object_type_id' => ModelStub::ulid('aging-expression-type'),
        'clock' => AgingClock::UpdatedAt->value,
        'clock_field_key' => null,
        'condition' => null,
        'thresholds' => [
            ['after_days' => 30, 'color' => AgingThresholdColor::Red->value],
            ['after_days' => 7, 'color' => AgingThresholdColor::Amber->value],
        ],
        'is_active' => true,
        ...$attributes,
    ]);

    $this->fieldWith = fn (array $attributes = []): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('aging-clock-field'),
        'object_type_id' => ModelStub::ulid('aging-expression-type'),
        'key' => 'closed_on',
        'field_type' => FieldType::Date->value,
        'is_encrypted' => false,
        'is_translatable' => false,
        ...$attributes,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('measures the age in whole days against the record update timestamp in utc', function (): void {
    expect($this->builder->ageExpression(($this->ruleWith)(), new Collection))
        ->toBe("(EXTRACT(EPOCH FROM (now() - (custom_records.updated_at AT TIME ZONE 'UTC'))) / 86400)");
});

it('ranks the thresholds by their day count no matter in which order they were stored', function (): void {
    expect($this->builder->rankedThresholds(($this->ruleWith)()))->toBe([
        ['after_days' => 7, 'stage' => 1, 'color' => AgingThresholdColor::Amber->value],
        ['after_days' => 30, 'stage' => 2, 'color' => AgingThresholdColor::Red->value],
    ]);
});

it('walks the thresholds from the highest down so the reached stage wins', function (): void {
    $stage = $this->builder->stageExpression(($this->ruleWith)(), new Collection);

    expect($stage['bindings'])->toBe([30, 2, 7, 1])
        ->and(substr_count($stage['sql'], 'WHEN'))->toBe(2)
        ->and($stage['sql'])->toStartWith('(CASE WHEN ')
        ->and($stage['sql'])->toContain('>= ?::numeric THEN ?::int')
        ->and($stage['sql'])->toEndWith(' END)');
});

it('returns the colour of the reached threshold as text', function (): void {
    $color = $this->builder->colorExpression(($this->ruleWith)(), new Collection);

    expect($color['bindings'])->toBe([30, AgingThresholdColor::Red->value, 7, AgingThresholdColor::Amber->value])
        ->and($color['sql'])->toContain('>= ?::numeric THEN ?::text');
});

it('yields no stage at all for a rule without a single threshold', function (): void {
    expect($this->builder->stageExpression(($this->ruleWith)(['thresholds' => []]), new Collection))
        ->toBe(['sql' => 'NULL', 'bindings' => []]);
});

it('measures a custom date field through the index expression of that field', function (): void {
    $rule = ($this->ruleWith)(['clock' => AgingClock::Field->value, 'clock_field_key' => 'closed_on']);

    expect($this->builder->ageExpression($rule, new Collection([($this->fieldWith)()])))
        ->toContain('closed_on');
});

it('refuses to measure a field the given field set does not carry', function (): void {
    $rule = ($this->ruleWith)(['clock' => AgingClock::Field->value, 'clock_field_key' => 'closed_on']);

    expect(fn (): string => $this->builder->ageExpression($rule, new Collection))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to measure a field that holds no date', function (): void {
    $rule = ($this->ruleWith)(['clock' => AgingClock::Field->value, 'clock_field_key' => 'closed_on']);
    $fields = new Collection([($this->fieldWith)(['field_type' => FieldType::TextShort->value])]);

    expect(fn (): string => $this->builder->ageExpression($rule, $fields))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to measure an encrypted or translatable date field', function (): void {
    $rule = ($this->ruleWith)(['clock' => AgingClock::Field->value, 'clock_field_key' => 'closed_on']);

    expect(fn (): string => $this->builder->ageExpression($rule, new Collection([($this->fieldWith)(['is_encrypted' => true])])))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => $this->builder->ageExpression($rule, new Collection([($this->fieldWith)(['is_translatable' => true])])))
        ->toThrow(InvalidArgumentException::class);
});

it('takes the highest age of every rule when several rules age the same record', function (): void {
    $rules = new Collection([
        ($this->ruleWith)(),
        ($this->ruleWith)(['id' => ModelStub::ulid('aging-expression-rule-two')]),
    ]);

    $highest = $this->builder->highestAgeExpression($rules, new Collection);

    expect($highest)->not->toBeNull()
        ->and($highest['sql'])->toStartWith('GREATEST(')
        ->and(substr_count((string) $highest['sql'], 'EXTRACT(EPOCH'))->toBe(2);
});
